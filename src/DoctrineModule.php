<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine;

use dev\winterframework\core\app\WinterModule;
use dev\winterframework\stereotype\Module;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterBeanProviderContext;
use dev\winterframework\doctrine\common\DoctrineComponentBuilder;
use dev\winterframework\doctrine\dbal\DbalTransactionManager;
use dev\winterframework\doctrine\orm\EmTransactionManager;
use dev\winterframework\exception\NoUniqueBeanDefinitionException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;

#[Module]
class DoctrineModule  implements WinterModule {

    public function init(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
    }

    public function begin(ApplicationContext $ctx, ApplicationContextData $ctxData): void {
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
