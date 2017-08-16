<?php
declare(strict_types=1);

namespace AppBundle\DataFixture;

use AppBundle\Entity\Detail;
use Doctrine\Common\Persistence\ObjectManager;
use Fidry\AliceDataFixtures\LoaderInterface;
use Fidry\AliceDataFixtures\Persistence\PersisterAwareInterface;
use Fidry\AliceDataFixtures\Persistence\PersisterInterface;
use Fidry\AliceDataFixtures\Persistence\PurgeMode;

final class DetailProcessorLoader implements LoaderInterface, PersisterAwareInterface
{
    /**
     * @var LoaderInterface|PersisterAwareInterface
     */
    private $loader;

    private $objectManager;

    public function __construct(LoaderInterface $decoratedLoader, ObjectManager $objectManager
    ) {
        $this->loader = $decoratedLoader;
        $this->objectManager = $objectManager;
    }

    /**
     * @inheritdoc
     */
    public function withPersister(PersisterInterface $persister)
    {
        // Assumes the decorated loader is persister aware. If this assumption is not always verified, it might be
        // appropriate to add an additional check here
        $decoratedLoader = $this->loader->withPersister($persister);

        return new self($decoratedLoader, $this->objectManager);
    }

    /**
     * Pre process, persist and post process each object loaded.
     *
     * {@inheritdoc}
     */
    public function load(array $fixturesFiles, array $parameters = [], array $objects = [], PurgeMode $purgeMode = null): array
    {
        $objects = $this->loader->load($fixturesFiles, $parameters, $objects, $purgeMode);

        foreach ($objects as $id => $object) {
            if ($object instanceof Detail) {
                $this->objectManager->persist($object);
            }
        }

        $this->objectManager->flush();
        $this->objectManager->clear();

        return $objects;
    }
}