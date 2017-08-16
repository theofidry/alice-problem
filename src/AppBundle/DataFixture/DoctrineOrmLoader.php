<?php
declare(strict_types=1);

namespace AppBundle\DataFixture;

use Doctrine\DBAL\Sharding\PoolingShardConnection;
use Doctrine\ORM\EntityManagerInterface;
use Fidry\AliceDataFixtures\Bridge\Doctrine\Purger\Purger;
use Fidry\AliceDataFixtures\Loader\FileResolverLoader;
use Fidry\AliceDataFixtures\Loader\PurgerLoader;
use Fidry\AliceDataFixtures\LoaderInterface;
use Fidry\AliceDataFixtures\Persistence\PersisterAwareInterface;
use Fidry\AliceDataFixtures\Persistence\PurgeMode;
use Hautelook\AliceBundle\BundleResolverInterface;
use Hautelook\AliceBundle\FixtureLocatorInterface;
use Hautelook\AliceBundle\LoaderInterface as AliceBundleLoaderInterface;
use Hautelook\AliceBundle\LoggerAwareInterface;
use Hautelook\AliceBundle\Resolver\File\KernelFileResolver;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\HttpKernel\KernelInterface;

final class DoctrineOrmLoader implements AliceBundleLoaderInterface, LoggerAwareInterface
{
    /**
     * @var BundleResolverInterface
     */
    private $bundleResolver;

    /**
     * @var FixtureLocatorInterface
     */
    private $fixtureLocator;

    /**
     * @var LoaderInterface|PersisterAwareInterface
     */
    private $loader;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        BundleResolverInterface $bundleResolver,
        FixtureLocatorInterface $fixtureLocator,
        LoaderInterface $loader,
        LoggerInterface $logger
    ) {
        $this->bundleResolver = $bundleResolver;
        $this->fixtureLocator = $fixtureLocator;
        if (false === $loader instanceof PersisterAwareInterface) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Expected loader to be an instance of "%s".',
                    PersisterAwareInterface::class
                )
            );
        }
        $this->loader = $loader;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function withLogger(LoggerInterface $logger): self
    {
        return new self($this->bundleResolver, $this->fixtureLocator, $this->loader, $logger);
    }

    /**
     * @inheritdoc
     */
    public function load(
        Application $application,
        EntityManagerInterface $manager,
        array $bundles,
        string $environment,
        bool $append,
        bool $purgeWithTruncate,
        string $shard = null
    ) {
        $bundles = $this->bundleResolver->resolveBundles($application, $bundles);
        $fixtureFiles = $this->fixtureLocator->locateFiles($bundles, $environment);

        $this->logger->info('fixtures found', ['files' => $fixtureFiles]);

        if (null !== $shard) {
            $this->connectToShardConnection($manager, $shard);
        }

        $fixtures = $this->loadFixtures(
            $this->loader,
            $application->getKernel(),
            $manager,
            $fixtureFiles,
            $application->getKernel()->getContainer()->getParameterBag()->all(),
            $append,
            $purgeWithTruncate
        );

        $this->logger->info('fixtures loaded');

        return $fixtures;
    }

    private function connectToShardConnection(EntityManagerInterface $manager, string $shard)
    {
        $connection = $manager->getConnection();
        if ($connection instanceof PoolingShardConnection) {
            $connection->connect($shard);

            return;
        }

        throw new \InvalidArgumentException(
            sprintf(
                'Could not establish a shard connection for the shard "%s". The connection must be an instance'
                .' of "%s", got "%s" instead.',
                $shard,
                PoolingShardConnection::class,
                get_class($connection)
            )
        );
    }

    /**
     * @param LoaderInterface|PersisterAwareInterface $loader
     * @param KernelInterface                         $kernel
     * @param EntityManagerInterface                  $manager
     * @param string[]                                $files
     * @param array                                   $parameters
     * @param bool                                    $append
     * @param bool|null                               $purgeWithTruncate
     *
     * @return \object[]
     */
    private function loadFixtures(
        LoaderInterface $loader,
        KernelInterface $kernel,
        EntityManagerInterface $manager,
        array $files,
        array $parameters,
        bool $append,
        bool $purgeWithTruncate
    ) {
        if ($append && $purgeWithTruncate) {
            throw new LogicException(
                'Cannot append loaded fixtures and at the same time purge the database. Choose one.'
            );
        }

        # That's the changed line...
        $loader = $loader->withPersister(new ObjectManagerPersister($manager));
        if (true === $append) {
            return $loader->load($files, $parameters);
        }

        $purgeMode = (true === $purgeWithTruncate)
            ? PurgeMode::createTruncateMode()
            : PurgeMode::createDeleteMode()
        ;

        $purger = new Purger($manager, $purgeMode);
        $loader = new PurgerLoader($loader, $purger, $purger);
        $loader = new FileResolverLoader($loader, new KernelFileResolver($kernel));

        return $loader->load($files, $parameters);
    }
}
