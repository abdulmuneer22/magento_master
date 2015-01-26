<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Tools\Di\Compiler\Config;

use Magento\Framework\App\Area;
use Magento\Tools\Di\Definition\Collection;

class ReaderTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \Magento\Tools\Di\Compiler\Config\Reader
     */
    protected $model;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $diContainerConfig;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $configLoader;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $argumentsResolverFactory;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $argumentsResolver;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $classReaderDecorator;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $typeReader;

    protected function setUp()
    {
        $this->diContainerConfig = $this->getMock('Magento\Framework\ObjectManager\ConfigInterface', [], [], '', false);
        $this->configLoader = $this->getMock('Magento\Framework\App\ObjectManager\ConfigLoader', [], [], '', false);
        $this->argumentsResolverFactory = $this->getMock(
            'Magento\Tools\Di\Compiler\ArgumentsResolverFactory',
            [],
            [],
            '',
            false
        );
        $this->argumentsResolver = $this->getMock('Magento\Tools\Di\Compiler\ArgumentsResolver', [], [], '', false);
        $this->argumentsResolverFactory->expects($this->any())
            ->method('create')
            ->willReturn($this->argumentsResolver);
        $this->classReaderDecorator = $this->getMock(
            'Magento\Tools\Di\Code\Reader\ClassReaderDecorator',
            [],
            [],
            '',
            false
        );
        $this->typeReader = $this->getMock('Magento\Tools\Di\Code\Reader\Type', [], [], '', false);

        $this->model = new \Magento\Tools\Di\Compiler\Config\Reader(
            $this->diContainerConfig,
            $this->configLoader,
            $this->argumentsResolverFactory,
            $this->classReaderDecorator,
            $this->typeReader
        );
    }

    public function testGenerateCachePerScopeGlobal()
    {
        $definitionCollection = $this->getDefinitionsCollection();
        $this->diContainerConfig->expects($this->any())
            ->method('getVirtualTypes')
            ->willReturn($this->getVirtualTypes());
        $this->diContainerConfig->expects($this->any())
            ->method('getPreferences')
            ->willReturn($this->getPreferences());

        $getResolvedConstructorArgumentsMap = $this->getResolvedVirtualConstructorArgumentsMap(
            $definitionCollection,
            $this->getVirtualTypes()
        );

        $this->diContainerConfig->expects($this->any())
            ->method('getInstanceType')
            ->willReturnMap($this->getInstanceTypeMap($this->getVirtualTypes()));

        $this->diContainerConfig->expects($this->any())
            ->method('isShared')
            ->willReturnMap($this->getExpectedNonShared());

        $this->diContainerConfig->expects($this->any())
            ->method('getPreference')
            ->willReturnMap($this->getPreferencesMap());

        $isConcreteMap = [];
        foreach ($definitionCollection->getInstancesNamesList() as $instanceType) {
            $isConcreteMap[] = [$instanceType, strpos($instanceType, 'Interface') === false];

            $getResolvedConstructorArgumentsMap[] = [
                $instanceType,
                $definitionCollection->getInstanceArguments($instanceType),
                $this->getResolvedArguments(
                    $definitionCollection->getInstanceArguments($instanceType)
                )
            ];
        }

        $this->typeReader->expects($this->any())
            ->method('isConcrete')
            ->willReturnMap($isConcreteMap);
        $this->argumentsResolver->expects($this->any())
            ->method('getResolvedConstructorArguments')
            ->willReturnMap($getResolvedConstructorArgumentsMap);

        $this->assertEquals(
            $this->getExpectedGlobalConfig(),
            $this->model->generateCachePerScope($definitionCollection, Area::AREA_GLOBAL)
        );
    }

    /**
     * @return array
     */
    private function getExpectedGlobalConfig()
    {
        return [
            'arguments' => [
                'ConcreteType1' => serialize(['resolved_argument1', 'resolved_argument2']),
                'ConcreteType2' => serialize(['resolved_argument1', 'resolved_argument2']),
                'virtualType1' => serialize(['resolved_argument1', 'resolved_argument2'])
            ],
            'nonShared' => [
                'ConcreteType2' => true,
                'ThirdPartyInterface' => true
            ],
            'preferences' => $this->getPreferences(),
            'instanceTypes' => $this->getVirtualTypes(),
        ];
    }

    /**
     * @return Collection
     */
    private function getDefinitionsCollection()
    {
        $definitionCollection = new Collection();
        $definitionCollection->addDefinition('ConcreteType1', ['argument1', 'argument2']);
        $definitionCollection->addDefinition('ConcreteType2', ['argument1', 'argument2']);
        $definitionCollection->addDefinition('Interface1', [null]);

        return $definitionCollection;
    }

    /**
     * @return array
     */
    private function getVirtualTypes()
    {
        return ['virtualType1' => 'ConcreteType1'];
    }

    /**
     * @return array
     */
    private function getExpectedNonShared()
    {
        return [
            ['ConcreteType1', true],
            ['ConcreteType2', false],
            ['Interface1', true],
            ['ThirdPartyInterface', false]
        ];
    }

    /**
     * @return array
     */
    private function getPreferences()
    {
        return [
            'Interface1' => 'ConcreteType1',
            'ThirdPartyInterface' => 'ConcreteType2'
        ];
    }

    /**
     * @return array
     */
    private function getPreferencesMap()
    {
        return [
            ['ConcreteType1', 'ConcreteType1'],
            ['ConcreteType2', 'ConcreteType2'],
            ['Interface1', 'ConcreteType1'],
            ['ThirdPartyInterface', 'ConcreteType2']
        ];
    }

    /**
     * @param array $arguments
     * @return array|null
     */
    private function getResolvedArguments($arguments)
    {
        return empty($arguments) ? null : array_map(
            function ($argument) {
                return 'resolved_' . $argument;
            },
            $arguments
        );
    }

    /**
     * @param array $virtualTypes
     * @return array
     */
    private function getInstanceTypeMap($virtualTypes)
    {
        $getInstanceTypeMap = [];
        foreach ($virtualTypes as $virtualType => $concreteType) {
            $getInstanceTypeMap[] = [$virtualType, $concreteType];
        }

        return $getInstanceTypeMap;
    }

    /**
     * @param Collection $definitionCollection
     * @param array $virtualTypes
     * @return array
     */
    private function getResolvedVirtualConstructorArgumentsMap(Collection $definitionCollection, array $virtualTypes)
    {
        $getResolvedConstructorArgumentsMap = [];
        foreach ($virtualTypes as $virtualType => $concreteType) {
            $getResolvedConstructorArgumentsMap[] = [
                $virtualType,
                $definitionCollection->getInstanceArguments($concreteType),
                $this->getResolvedArguments(
                    $definitionCollection->getInstanceArguments($concreteType)
                )
            ];
        }
        return $getResolvedConstructorArgumentsMap;
    }
}