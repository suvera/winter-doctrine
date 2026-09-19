<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine;

use dev\winterframework\core\app\WinterModule;
use dev\winterframework\stereotype\Module;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterBeanProviderContext;
use dev\winterframework\doctrine\common\DoctrineComponentBuilder;
use dev\winterframework\coroutine\SwooleCoroutineScopeProvider;
use dev\winterframework\doctrine\dbal\DbalTransactionManager;
use dev\winterframework\doctrine\multitenancy\MultiTenantManager;
use dev\winterframework\doctrine\orm\EmTransactionManager;
use dev\winterframework\exception\ClassNotFoundException;
use dev\winterframework\exception\NoUniqueBeanDefinitionException;
use dev\winterframework\exception\WinterException;
use dev\winterframework\pdbc\multitenant\TenantDataSourceProvider;
use dev\winterframework\type\TypeAssert;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use dev\winterframework\doctrine\common\TolerantDateTimeTzImmutableType;
use Override;
use Throwable;

#[Module]
class DoctrineModule  implements WinterModule {

    #[Override]
    public function init(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        Type::overrideType(Types::DATETIMETZ_IMMUTABLE, TolerantDateTimeTzImmutableType::class);
    }

    #[Override]
    public function begin(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        $this->registerMultiTenantDataSources($ctx, $ctxData);
        $this->registerStandardDataSources($ctx, $ctxData);
    }

    private function resolveCoroutineScoping(ApplicationContextData $ctxData): bool {
        try {
            $props = $ctxData->getPropertyContext();
            if ($props->has(DoctrineComponentBuilder::COROUTINE_SCOPED_FLAG)) {
                return (bool)filter_var(
                    $props->get(DoctrineComponentBuilder::COROUTINE_SCOPED_FLAG),
                    FILTER_VALIDATE_BOOLEAN
                );
            }
        } catch (Throwable) {
        }
        return SwooleCoroutineScopeProvider::isAvailable();
    }

    /**
     * @return array{0: int, 1: int} [maxDelegates, maxWaitMs]
     */
    private function resolveCoroutineCaps(ApplicationContextData $ctxData): array {
        $max = 50;
        $wait = 5000;
        try {
            $props = $ctxData->getPropertyContext();
            if ($props->has(DoctrineComponentBuilder::COROUTINE_MAX_DELEGATES_FLAG)) {
                $max = max(0, (int)$props->get(DoctrineComponentBuilder::COROUTINE_MAX_DELEGATES_FLAG));
            }
            if ($props->has(DoctrineComponentBuilder::COROUTINE_MAX_WAIT_MS_FLAG)) {
                $wait = max(0, (int)$props->get(DoctrineComponentBuilder::COROUTINE_MAX_WAIT_MS_FLAG));
            }
        } catch (Throwable) {
        }
        return [$max, $wait];
    }

    private function registerMultiTenantDataSources(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        if (!$ctxData->getPropertyContext()->has('multitenant-datasource')) {
            return;
        }

        $mts = $ctxData->getPropertyContext()->get('multitenant-datasource');
        if (!is_array($mts) || empty($mts)) {
            return;
        }

        foreach ($mts as $mtDs) {
            if (!isset($mtDs['name'])) {
                throw new WinterException(
                    'multitenant-datasource DataSource configured without "name" parameter'
                );
            }
            if (!isset($mtDs['providerClass'])) {
                throw new WinterException(
                    'multitenant-datasource DataSource configured without "providerClass" parameter'
                );
            }

            if (!class_exists($mtDs['providerClass'], true)) {
                throw new ClassNotFoundException(
                    'multitenant-datasource providerClass does not exist "' . $mtDs['providerClass'] . '"'
                );
            }

            TypeAssert::objectOfIsA(
                $mtDs['providerClass'],
                TenantDataSourceProvider::class,
                'multitenant-datasource "providerClass" must be derived from ' . TenantDataSourceProvider::class
            );

            $providerClass = $mtDs['providerClass'];
            $beanProvider = $ctxData->getBeanProvider();

            [$maxDelegates, $maxWaitMs] = $this->resolveCoroutineCaps($ctxData);
            $mtManager = new MultiTenantManager(
                $providerClass,
                $ctx,
                null,
                $this->resolveCoroutineScoping($ctxData),
                $maxDelegates,
                $maxWaitMs
            );
            $beanProvider->registerInternalBean(
                $mtManager,
                MultiTenantManager::class,
                true,
                $mtDs['name'] . '-manager'
            );
        }
    }

    private function registerStandardDataSources(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
        if (!$ctxData->getPropertyContext()->has('datasource')) {
            return;
        }

        $ds = $ctxData->getPropertyContext()->get('datasource');
        if (!is_array($ds) || empty($ds)) {
            return;
        }
        /** 
         * @var WinterBeanProviderContext $beanProvider
         */
        $beanProvider = $ctxData->getBeanProvider();

        $dsBuilder = new DoctrineComponentBuilder($ctx, $ctxData, $ds);
        foreach ($dsBuilder->getDoctrineDbConfig() as $beanName => $config) {
            $emBeanName = $beanName . DoctrineComponentBuilder::DOCTRINE_EM_SUFFIX;
            $txnBeanName = $beanName . DoctrineComponentBuilder::DOCTRINE_TXN_SUFFIX;

            $dbalConnBeanName = $beanName . DoctrineComponentBuilder::DOCTRINE_CONN_SUFFIX;
            $dbalTxnBeanName = $beanName . DoctrineComponentBuilder::DOCTRINE_DBAL_TXN_SUFFIX;

            if ($ctx->hasBeanByName($emBeanName)) {
                throw new NoUniqueBeanDefinitionException(
                    'DataSource creation failed, '
                        . 'due to no qualifying bean with name '
                        . "'$emBeanName' available: expected single matching bean but found multiple "
                        . EntityManager::class
                );
            }

            if ($ctx->hasBeanByName($txnBeanName)) {
                throw new NoUniqueBeanDefinitionException(
                    'EntityManager creation failed, '
                        . 'due to no qualifying bean with name '
                        . "'$txnBeanName' available: expected single matching bean but found multiple "
                        . EmTransactionManager::class
                );
            }

            if ($ctx->hasBeanByName($dbalConnBeanName)) {
                throw new NoUniqueBeanDefinitionException(
                    'EntityManager creation failed, '
                        . 'due to no qualifying bean with name '
                        . "'$dbalConnBeanName' available: expected single matching bean but found multiple "
                        . Connection::class
                );
            }

            if ($ctx->hasBeanByName($dbalTxnBeanName)) {
                throw new NoUniqueBeanDefinitionException(
                    'EntityManager creation failed, '
                        . 'due to no qualifying bean with name '
                        . "'$dbalTxnBeanName' available: expected single matching bean but found multiple "
                        . DbalTransactionManager::class
                );
            }

            // ── Register standard (non-tenant) beans ──
            $beanProvider->registerInternalBeanMethod(
                $emBeanName,
                $config->isPrimary() ? EntityManager::class : '',
                $dsBuilder,
                $config->isPrimary() ? 'getPrimaryEntityManager' : 'getEntityManager',
                $config->isPrimary() ? [] : ['name' => $emBeanName],
                false
            );

            $beanProvider->registerInternalBeanMethod(
                $txnBeanName,
                $config->isPrimary() ? EmTransactionManager::class : '',
                $dsBuilder,
                $config->isPrimary() ? 'getPrimaryTransactionManager' : 'getTransactionManager',
                $config->isPrimary() ? [] : ['name' => $txnBeanName],
                false
            );

            $beanProvider->registerInternalBeanMethod(
                $dbalConnBeanName,
                $config->isPrimary() ? Connection::class : '',
                $dsBuilder,
                $config->isPrimary() ? 'getPrimaryConnection' : 'getConnection',
                $config->isPrimary() ? [] : ['name' => $dbalConnBeanName],
                false
            );

            $beanProvider->registerInternalBeanMethod(
                $dbalTxnBeanName,
                $config->isPrimary() ? DbalTransactionManager::class : '',
                $dsBuilder,
                $config->isPrimary() ? 'getPrimaryDbalTransactionManager' : 'getDbalTransactionManager',
                $config->isPrimary() ? [] : ['name' => $dbalTxnBeanName],
                false
            );
        }
    }
}
