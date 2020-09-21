<?php

/**
 * Shopware 5
 * Copyright (c) shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */

namespace Shopware\Components\DependencyInjection;

use Composer\Autoload\ClassLoader;
use Symfony\Component\Debug\DebugClassLoader as LegacyDebugClassLoader;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceLocator;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Compiler\AnalyzeServiceReferencesPass;
use Symfony\Component\DependencyInjection\Compiler\CheckCircularReferencesPass;
use Symfony\Component\DependencyInjection\Compiler\ServiceReferenceGraphNode;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Dumper\Dumper;
use Symfony\Component\DependencyInjection\Exception\EnvParameterException;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException;
use Symfony\Component\DependencyInjection\ExpressionLanguage;
use Symfony\Component\DependencyInjection\LazyProxy\PhpDumper\DumperInterface as ProxyDumper;
use Symfony\Component\DependencyInjection\LazyProxy\PhpDumper\NullDumper;
use Symfony\Component\DependencyInjection\Loader\FileLoader;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator as BaseServiceLocator;
use Symfony\Component\DependencyInjection\TypedReference;
use Symfony\Component\DependencyInjection\Variable;
use Symfony\Component\ErrorHandler\DebugClassLoader;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpKernel\Kernel;

/**
 * PhpDumper dumps a service container as a PHP class.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class LegacyPhpDumper extends Dumper
{
    /**
     * Characters that might appear in the generated variable name as first character.
     */
    const FIRST_CHARS = 'abcdefghijklmnopqrstuvwxyz';

    /**
     * Characters that might appear in the generated variable name as any but the first character.
     */
    const NON_FIRST_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789_';

    private $definitionVariables;
    private $referenceVariables;
    private $variableCount;
    private $inlinedDefinitions;
    private $serviceCalls;
    private $reservedVariables = ['instance', 'class', 'this'];
    private $expressionLanguage;
    private $targetDirRegex;
    private $targetDirMaxMatches;
    private $docStar;
    private $serviceIdToMethodNameMap;
    private $usedMethodNames;
    private $namespace;
    private $asFiles;
    private $hotPathTag;
    private $inlineFactories;
    private $inlineRequires;
    private $inlinedRequires = [];
    private $circularReferences = [];
    private $singleUsePrivateIds = [];
    private $addThrow = false;
    private $addGetService = false;
    private $locatedIds = [];
    private $serviceLocatorTag;
    private $exportedVariables = [];
    private $baseClass;

    /**
     * @var ProxyDumper
     */
    private $proxyDumper;

    /**
     * {@inheritdoc}
     */
    public function __construct(ContainerBuilder $container)
    {
        if (!$container->isCompiled()) {
            throw new LogicException('Cannot dump an uncompiled container.');
        }

        parent::__construct($container);
    }

    /**
     * Sets the dumper to be used when dumping proxies in the generated container.
     */
    public function setProxyDumper(ProxyDumper $proxyDumper)
    {
        $this->proxyDumper = $proxyDumper;
    }

    /**
     * Dumps the service container as a PHP class.
     *
     * Available options:
     *
     *  * class:      The class name
     *  * base_class: The base class name
     *  * namespace:  The class namespace
     *  * as_files:   To split the container in several files
     *
     * @return string|array A PHP class representing the service container or an array of PHP files if the "as_files" option is set
     *
     * @throws EnvParameterException When an env var exists but has not been dumped
     */
    public function dump(array $options = [])
    {
        $this->locatedIds = [];
        $this->targetDirRegex = null;
        $this->inlinedRequires = [];
        $this->exportedVariables = [];
        $options = array_merge([
            'class' => 'ProjectServiceContainer',
            'base_class' => 'Container',
            'namespace' => '',
            'as_files' => false,
            'debug' => true,
            'hot_path_tag' => 'container.hot_path',
            'inline_factories_parameter' => 'container.dumper.inline_factories',
            'inline_class_loader_parameter' => 'container.dumper.inline_class_loader',
            'service_locator_tag' => 'container.service_locator',
            'build_time' => time(),
        ], $options);

        $this->addThrow = $this->addGetService = false;
        $this->namespace = $options['namespace'];
        $this->asFiles = $options['as_files'];
        $this->hotPathTag = $options['hot_path_tag'];
        $this->inlineFactories = $this->asFiles && $options['inline_factories_parameter'] && $this->container->hasParameter($options['inline_factories_parameter']) && $this->container->getParameter($options['inline_factories_parameter']);
        $this->inlineRequires = $options['inline_class_loader_parameter'] && $this->container->hasParameter($options['inline_class_loader_parameter']) && $this->container->getParameter($options['inline_class_loader_parameter']);
        $this->serviceLocatorTag = $options['service_locator_tag'];

        if (0 !== strpos($baseClass = $options['base_class'], '\\') && 'Container' !== $baseClass) {
            $baseClass = sprintf('%s\%s', $options['namespace'] ? '\\'.$options['namespace'] : '', $baseClass);
            $this->baseClass = $baseClass;
        } elseif ('Container' === $baseClass) {
            $this->baseClass = Container::class;
        } else {
            $this->baseClass = $baseClass;
        }

        $this->initializeMethodNamesMap('Container' === $baseClass ? Container::class : $baseClass);

        if ($this->getProxyDumper() instanceof NullDumper) {
            (new AnalyzeServiceReferencesPass(true, false))->process($this->container);
            try {
                (new CheckCircularReferencesPass())->process($this->container);
            } catch (ServiceCircularReferenceException $e) {
                $path = $e->getPath();
                end($path);
                $path[key($path)] .= '". Try running "composer require symfony/proxy-manager-bridge';

                throw new ServiceCircularReferenceException($e->getServiceId(), $path);
            }
        }

        (new AnalyzeServiceReferencesPass(false, !$this->getProxyDumper() instanceof NullDumper))->process($this->container);
        $checkedNodes = [];
        $this->circularReferences = [];
        $this->singleUsePrivateIds = [];
        foreach ($this->container->getCompiler()->getServiceReferenceGraph()->getNodes() as $id => $node) {
            if (!$node->getValue() instanceof Definition) {
                continue;
            }
            if (!isset($checkedNodes[$id])) {
                $this->analyzeCircularReferences($id, $node->getOutEdges(), $checkedNodes);
            }
            if ($this->isSingleUsePrivateNode($node)) {
                $this->singleUsePrivateIds[$id] = $id;
            }
        }
        $this->container->getCompiler()->getServiceReferenceGraph()->clear();
        $checkedNodes = [];
        $this->singleUsePrivateIds = array_diff_key($this->singleUsePrivateIds, $this->circularReferences);

        $this->docStar = $options['debug'] ? '*' : '';

        if (!empty($options['file']) && is_dir($dir = \dirname($options['file']))) {
            // Build a regexp where the first root dirs are mandatory,
            // but every other sub-dir is optional up to the full path in $dir
            // Mandate at least 2 root dirs and not more that 5 optional dirs.

            $dir = explode(\DIRECTORY_SEPARATOR, realpath($dir));
            $i = \count($dir);

            if (3 <= $i) {
                $regex = '';
                $lastOptionalDir = $i > 8 ? $i - 5 : 3;
                $this->targetDirMaxMatches = $i - $lastOptionalDir;

                while (--$i >= $lastOptionalDir) {
                    $regex = sprintf('(%s%s)?', preg_quote(\DIRECTORY_SEPARATOR.$dir[$i], '#'), $regex);
                }

                do {
                    $regex = preg_quote(\DIRECTORY_SEPARATOR.$dir[$i], '#').$regex;
                } while (0 < --$i);

                $this->targetDirRegex = '#'.preg_quote($dir[0], '#').$regex.'#';
            }
        }

        $proxyClasses = $this->inlineFactories ? $this->generateProxyClasses() : null;

        $code =
            $this->startClass($options['class'], $baseClass, $preload).
            $this->addServices($services).
            $this->addDeprecatedAliases().
            $this->addDefaultParametersMethod()
        ;

        $proxyClasses = $proxyClasses ?? $this->generateProxyClasses();

        if ($this->addGetService) {
            $code = preg_replace(
                "/(\r?\n\r?\n    public function __construct.+?\\{\r?\n)/s",
                "\n    private \$getService;$1        \$this->getService = \\Closure::fromCallable([\$this, 'getService']);\n",
                $code,
                1
            );
        }

        if ($this->asFiles) {
            $fileStart = <<<EOF
<?php

use Symfony\Component\DependencyInjection\Argument\RewindableGenerator;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

// This file has been auto-generated by the Symfony Dependency Injection Component for internal use.

EOF;
            $files = [];

            $ids = $this->container->getRemovedIds();
            foreach ($this->container->getDefinitions() as $id => $definition) {
                if (!$definition->isPublic()) {
                    $ids[$id] = true;
                }
            }
            if ($ids = array_keys($ids)) {
                sort($ids);
                $c = "<?php\n\nreturn [\n";
                foreach ($ids as $id) {
                    $c .= '    '.$this->doExport($id)." => true,\n";
                }
                $files['removed-ids.php'] = $c."];\n";
            }

            if (!$this->inlineFactories) {
                foreach ($this->generateServiceFiles($services) as $file => $c) {
                    $files[$file] = $fileStart.$c;
                }
                foreach ($proxyClasses as $file => $c) {
                    $files[$file] = "<?php\n".$c;
                }
            }

            $code .= $this->endClass();

            if ($this->inlineFactories) {
                foreach ($proxyClasses as $c) {
                    $code .= $c;
                }
            }

            $files[$options['class'].'.php'] = $code;
            $hash = ucfirst(strtr(ContainerBuilder::hash($files), '._', 'xx'));
            $code = [];

            foreach ($files as $file => $c) {
                $code["Container{$hash}/{$file}"] = $c;
            }
            array_pop($code);
            $code["Container{$hash}/{$options['class']}.php"] = substr_replace($files[$options['class'].'.php'], "<?php\n\nnamespace Container{$hash};\n", 0, 6);
            $namespaceLine = $this->namespace ? "\nnamespace {$this->namespace};\n" : '';
            $time = $options['build_time'];
            $id = hash('crc32', $hash.$time);
            $this->asFiles = false;

            if ($preload && null !== $autoloadFile = $this->getAutoloadFile()) {
                $autoloadFile = substr($this->export($autoloadFile), 2, -1);

                $code[$options['class'].'.preload.php'] = <<<EOF
<?php

// This file has been auto-generated by the Symfony Dependency Injection Component
// You can reference it in the "opcache.preload" php.ini setting on PHP >= 7.4 when preloading is desired

use Symfony\Component\DependencyInjection\Dumper\Preloader;

require $autoloadFile;
require __DIR__.'/Container{$hash}/{$options['class']}.php';

\$classes = [];

EOF;

                foreach ($preload as $class) {
                    $code[$options['class'].'.preload.php'] .= sprintf("\$classes[] = '%s';\n", $class);
                }

                $code[$options['class'].'.preload.php'] .= <<<'EOF'

Preloader::preload($classes);

EOF;
            }

            $code[$options['class'].'.php'] = <<<EOF
<?php
{$namespaceLine}
// This file has been auto-generated by the Symfony Dependency Injection Component for internal use.

if (\\class_exists(\\Container{$hash}\\{$options['class']}::class, false)) {
    // no-op
} elseif (!include __DIR__.'/Container{$hash}/{$options['class']}.php') {
    touch(__DIR__.'/Container{$hash}.legacy');

    return;
}

if (!\\class_exists({$options['class']}::class, false)) {
    \\class_alias(\\Container{$hash}\\{$options['class']}::class, {$options['class']}::class, false);
}

return new \\Container{$hash}\\{$options['class']}([
    'container.build_hash' => '$hash',
    'container.build_id' => '$id',
    'container.build_time' => $time,
], __DIR__.\\DIRECTORY_SEPARATOR.'Container{$hash}');

EOF;
        } else {
            $code .= $this->endClass();
            foreach ($proxyClasses as $c) {
                $code .= $c;
            }
        }

        $this->targetDirRegex = null;
        $this->inlinedRequires = [];
        $this->circularReferences = [];
        $this->locatedIds = [];
        $this->exportedVariables = [];

        $unusedEnvs = [];
        foreach ($this->container->getEnvCounters() as $env => $use) {
            if (!$use) {
                $unusedEnvs[] = $env;
            }
        }
        if ($unusedEnvs) {
            throw new EnvParameterException($unusedEnvs, null, 'Environment variables "%s" are never used. Please, check your container\'s configuration.');
        }

        return $code;
    }

    /**
     * Retrieves the currently set proxy dumper or instantiates one.
     */
    private function getProxyDumper(): ProxyDumper
    {
        if (!$this->proxyDumper) {
            $this->proxyDumper = new NullDumper();
        }

        return $this->proxyDumper;
    }

    private function analyzeCircularReferences(string $sourceId, array $edges, array &$checkedNodes, array &$currentPath = [], bool $byConstructor = true)
    {
        $checkedNodes[$sourceId] = true;
        $currentPath[$sourceId] = $byConstructor;

        foreach ($edges as $edge) {
            $node = $edge->getDestNode();
            $id = $node->getId();

            if (!$node->getValue() instanceof Definition || $sourceId === $id || $edge->isLazy() || $edge->isWeak()) {
                // no-op
            } elseif (isset($currentPath[$id])) {
                $this->addCircularReferences($id, $currentPath, $edge->isReferencedByConstructor());
            } elseif (!isset($checkedNodes[$id])) {
                $this->analyzeCircularReferences($id, $node->getOutEdges(), $checkedNodes, $currentPath, $edge->isReferencedByConstructor());
            } elseif (isset($this->circularReferences[$id])) {
                $this->connectCircularReferences($id, $currentPath, $edge->isReferencedByConstructor());
            }
        }
        unset($currentPath[$sourceId]);
    }

    private function connectCircularReferences(string $sourceId, array &$currentPath, bool $byConstructor, array &$subPath = [])
    {
        $currentPath[$sourceId] = $subPath[$sourceId] = $byConstructor;

        foreach ($this->circularReferences[$sourceId] as $id => $byConstructor) {
            if (isset($currentPath[$id])) {
                $this->addCircularReferences($id, $currentPath, $byConstructor);
            } elseif (!isset($subPath[$id]) && isset($this->circularReferences[$id])) {
                $this->connectCircularReferences($id, $currentPath, $byConstructor, $subPath);
            }
        }
        unset($currentPath[$sourceId], $subPath[$sourceId]);
    }

    private function addCircularReferences(string $id, array $currentPath, bool $byConstructor)
    {
        $currentPath[$id] = $byConstructor;
        $circularRefs = [];

        foreach (array_reverse($currentPath) as $parentId => $v) {
            $byConstructor = $byConstructor && $v;
            $circularRefs[] = $parentId;

            if ($parentId === $id) {
                break;
            }
        }

        $currentId = $id;
        foreach ($circularRefs as $parentId) {
            if (empty($this->circularReferences[$parentId][$currentId])) {
                $this->circularReferences[$parentId][$currentId] = $byConstructor;
            }

            $currentId = $parentId;
        }
    }

    private function collectLineage(string $class, array &$lineage)
    {
        if (isset($lineage[$class])) {
            return;
        }
        if (!$r = $this->container->getReflectionClass($class, false)) {
            return;
        }
        if (is_a($class, $this->baseClass, true)) {
            return;
        }
        $file = $r->getFileName();
        if (!$file || $this->doExport($file) === $exportedFile = $this->export($file)) {
            return;
        }

        $lineage[$class] = substr($exportedFile, 1, -1);

        if ($parent = $r->getParentClass()) {
            $this->collectLineage($parent->name, $lineage);
        }

        foreach ($r->getInterfaces() as $parent) {
            $this->collectLineage($parent->name, $lineage);
        }

        foreach ($r->getTraits() as $parent) {
            $this->collectLineage($parent->name, $lineage);
        }

        unset($lineage[$class]);
        $lineage[$class] = substr($exportedFile, 1, -1);
    }

    private function generateProxyClasses(): array
    {
        $proxyClasses = [];
        $alreadyGenerated = [];
        $definitions = $this->container->getDefinitions();
        $strip = '' === $this->docStar && method_exists('Symfony\Component\HttpKernel\Kernel', 'stripComments');
        $proxyDumper = $this->getProxyDumper();
        ksort($definitions);
        foreach ($definitions as $definition) {
            if (!$proxyDumper->isProxyCandidate($definition)) {
                continue;
            }
            if (isset($alreadyGenerated[$class = $definition->getClass()])) {
                continue;
            }
            $alreadyGenerated[$class] = true;
            // register class' reflector for resource tracking
            $this->container->getReflectionClass($class);
            if ("\n" === $proxyCode = "\n".$proxyDumper->getProxyCode($definition)) {
                continue;
            }

            if ($this->inlineRequires) {
                $lineage = [];
                $this->collectLineage($class, $lineage);

                $code = '';
                foreach (array_diff_key(array_flip($lineage), $this->inlinedRequires) as $file => $class) {
                    if ($this->inlineFactories) {
                        $this->inlinedRequires[$file] = true;
                    }
                    $code .= sprintf("include_once %s;\n", $file);
                }

                $proxyCode = $code.$proxyCode;
            }

            if ($strip) {
                $proxyCode = "<?php\n".$proxyCode;
                $proxyCode = substr(Kernel::stripComments($proxyCode), 5);
            }

            $proxyClasses[sprintf('%s.php', explode(' ', $this->inlineRequires ? substr($proxyCode, \strlen($code)) : $proxyCode, 3)[1])] = $proxyCode;
        }

        return $proxyClasses;
    }

    private function addServiceInclude(string $cId, Definition $definition): string
    {
        $code = '';

        if ($this->inlineRequires && (!$this->isHotPath($definition) || $this->getProxyDumper()->isProxyCandidate($definition))) {
            $lineage = [];
            foreach ($this->inlinedDefinitions as $def) {
                if (!$def->isDeprecated() && \is_string($class = \is_array($factory = $def->getFactory()) && \is_string($factory[0]) ? $factory[0] : $def->getClass())) {
                    $this->collectLineage($class, $lineage);
                }
            }

            foreach ($this->serviceCalls as $id => list($callCount, $behavior)) {
                if ('service_container' !== $id && $id !== $cId
                    && ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE !== $behavior
                    && $this->container->has($id)
                    && $this->isTrivialInstance($def = $this->container->findDefinition($id))
                    && \is_string($class = \is_array($factory = $def->getFactory()) && \is_string($factory[0]) ? $factory[0] : $def->getClass())
                ) {
                    $this->collectLineage($class, $lineage);
                }
            }

            foreach (array_diff_key(array_flip($lineage), $this->inlinedRequires) as $file => $class) {
                $code .= sprintf("        include_once %s;\n", $file);
            }
        }

        foreach ($this->inlinedDefinitions as $def) {
            if ($file = $def->getFile()) {
                $file = $this->dumpValue($file);
                $file = '(' === $file[0] ? substr($file, 1, -1) : $file;
                $code .= sprintf("        include_once %s;\n", $file);
            }
        }

        if ('' !== $code) {
            $code .= "\n";
        }

        return $code;
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function addServiceInstance(string $id, Definition $definition, bool $isSimpleInstance): string
    {
        $class = $this->dumpValue($definition->getClass());

        if (0 === strpos($class, "'") && false === strpos($class, '$') && !preg_match('/^\'(?:\\\{2})?[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(?:\\\{2}[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)*\'$/', $class)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid class name for the "%s" service.', $class, $id));
        }

        $isProxyCandidate = $this->getProxyDumper()->isProxyCandidate($definition);
        $instantiation = '';

        $lastWitherIndex = null;
        foreach ($definition->getMethodCalls() as $k => $call) {
            if ($call[2] ?? false) {
                $lastWitherIndex = $k;
            }
        }

        if (!$isProxyCandidate && $definition->isShared() && !isset($this->singleUsePrivateIds[$id]) && null === $lastWitherIndex) {
            $instantiation = sprintf('$this->%s[%s] = %s', $this->container->getDefinition($id)->isPublic() ? 'services' : 'privates', $this->doExport($id), $isSimpleInstance ? '' : '$instance');
        } elseif (!$isSimpleInstance) {
            $instantiation = '$instance';
        }

        $return = '';
        if ($isSimpleInstance) {
            $return = 'return ';
        } else {
            $instantiation .= ' = ';
        }

        return $this->addNewInstance($definition, '        '.$return.$instantiation, $id);
    }

    private function isTrivialInstance(Definition $definition): bool
    {
        if ($definition->hasErrors()) {
            return true;
        }
        if ($definition->isSynthetic() || $definition->getFile() || $definition->getMethodCalls() || $definition->getProperties() || $definition->getConfigurator()) {
            return false;
        }
        if ($definition->isDeprecated() || $definition->isLazy() || $definition->getFactory() || 3 < \count($definition->getArguments())) {
            return false;
        }

        foreach ($definition->getArguments() as $arg) {
            if (!$arg || $arg instanceof Parameter) {
                continue;
            }
            if (\is_array($arg) && 3 >= \count($arg)) {
                foreach ($arg as $k => $v) {
                    if ($this->dumpValue($k) !== $this->dumpValue($k, false)) {
                        return false;
                    }
                    if (!$v || $v instanceof Parameter) {
                        continue;
                    }
                    if ($v instanceof Reference && $this->container->has($id = (string) $v) && $this->container->findDefinition($id)->isSynthetic()) {
                        continue;
                    }
                    if (!is_scalar($v) || $this->dumpValue($v) !== $this->dumpValue($v, false)) {
                        return false;
                    }
                }
            } elseif ($arg instanceof Reference && $this->container->has($id = (string) $arg) && $this->container->findDefinition($id)->isSynthetic()) {
                continue;
            } elseif (!is_scalar($arg) || $this->dumpValue($arg) !== $this->dumpValue($arg, false)) {
                return false;
            }
        }

        return true;
    }

    private function addServiceMethodCalls(Definition $definition, string $variableName, ?string $sharedNonLazyId): string
    {
        $lastWitherIndex = null;
        foreach ($definition->getMethodCalls() as $k => $call) {
            if ($call[2] ?? false) {
                $lastWitherIndex = $k;
            }
        }

        $calls = '';
        foreach ($definition->getMethodCalls() as $k => $call) {
            $arguments = [];
            foreach ($call[1] as $value) {
                $arguments[] = $this->dumpValue($value);
            }

            $witherAssignation = '';

            if ($call[2] ?? false) {
                if (null !== $sharedNonLazyId && $lastWitherIndex === $k) {
                    $witherAssignation = sprintf('$this->%s[\'%s\'] = ', $definition->isPublic() ? 'services' : 'privates', $sharedNonLazyId);
                }
                $witherAssignation .= sprintf('$%s = ', $variableName);
            }

            $calls .= $this->wrapServiceConditionals($call[1], sprintf("        %s\$%s->%s(%s);\n", $witherAssignation, $variableName, $call[0], implode(', ', $arguments)));
        }

        return $calls;
    }

    private function addServiceProperties(Definition $definition, string $variableName = 'instance'): string
    {
        $code = '';
        foreach ($definition->getProperties() as $name => $value) {
            $code .= sprintf("        \$%s->%s = %s;\n", $variableName, $name, $this->dumpValue($value));
        }

        return $code;
    }

    private function addServiceConfigurator(Definition $definition, string $variableName = 'instance'): string
    {
        if (!$callable = $definition->getConfigurator()) {
            return '';
        }

        if (\is_array($callable)) {
            if ($callable[0] instanceof Reference
                || ($callable[0] instanceof Definition && $this->definitionVariables->contains($callable[0]))
            ) {
                return sprintf("        %s->%s(\$%s);\n", $this->dumpValue($callable[0]), $callable[1], $variableName);
            }

            $class = $this->dumpValue($callable[0]);
            // If the class is a string we can optimize away
            if (0 === strpos($class, "'") && false === strpos($class, '$')) {
                return sprintf("        %s::%s(\$%s);\n", $this->dumpLiteralClass($class), $callable[1], $variableName);
            }

            if (0 === strpos($class, 'new ')) {
                return sprintf("        (%s)->%s(\$%s);\n", $this->dumpValue($callable[0]), $callable[1], $variableName);
            }

            return sprintf("        [%s, '%s'](\$%s);\n", $this->dumpValue($callable[0]), $callable[1], $variableName);
        }

        return sprintf("        %s(\$%s);\n", $callable, $variableName);
    }

    private function addService(string $id, Definition $definition): array
    {
        $this->definitionVariables = new \SplObjectStorage();
        $this->referenceVariables = [];
        $this->variableCount = 0;
        $this->referenceVariables[$id] = new Variable('instance');

        $return = [];

        if ($class = $definition->getClass()) {
            $class = $class instanceof Parameter ? '%'.$class.'%' : $this->container->resolveEnvPlaceholders($class);
            $return[] = sprintf(0 === strpos($class, '%') ? '@return object A %1$s instance' : '@return \%s', ltrim($class, '\\'));
        } elseif ($definition->getFactory()) {
            $factory = $definition->getFactory();
            if (\is_string($factory)) {
                $return[] = sprintf('@return object An instance returned by %s()', $factory);
            } elseif (\is_array($factory) && (\is_string($factory[0]) || $factory[0] instanceof Definition || $factory[0] instanceof Reference)) {
                $class = $factory[0] instanceof Definition ? $factory[0]->getClass() : (string) $factory[0];
                $class = $class instanceof Parameter ? '%'.$class.'%' : $this->container->resolveEnvPlaceholders($class);
                $return[] = sprintf('@return object An instance returned by %s::%s()', $class, $factory[1]);
            }
        }

        if ($definition->isDeprecated()) {
            if ($return && 0 === strpos($return[\count($return) - 1], '@return')) {
                $return[] = '';
            }

            $return[] = sprintf('@deprecated %s', $definition->getDeprecationMessage($id));
        }

        $return = str_replace("\n     * \n", "\n     *\n", implode("\n     * ", $return));
        $return = $this->container->resolveEnvPlaceholders($return);

        $shared = $definition->isShared() ? ' shared' : '';
        $public = $definition->isPublic() ? 'public' : 'private';
        $autowired = $definition->isAutowired() ? ' autowired' : '';

        if ($definition->isLazy()) {
            $lazyInitialization = '$lazyLoad = true';
        } else {
            $lazyInitialization = '';
        }

        $asFile = $this->asFiles && !$this->inlineFactories && !$this->isHotPath($definition);
        $methodName = $this->generateMethodName($id);
        if ($asFile) {
            $file = $methodName.'.php';
            $code = "        // Returns the $public '$id'$shared$autowired service.\n\n";
        } else {
            $file = null;
            $code = <<<EOF

    /*{$this->docStar}
     * Gets the $public '$id'$shared$autowired service.
     *
     * $return
EOF;
            $code = str_replace('*/', ' ', $code).<<<EOF

     */
    protected function {$methodName}($lazyInitialization)
    {

EOF;
        }

        $this->serviceCalls = [];
        $this->inlinedDefinitions = $this->getDefinitionsFromArguments([$definition], null, $this->serviceCalls);

        if ($definition->isDeprecated()) {
            $code .= sprintf("        @trigger_error(%s, E_USER_DEPRECATED);\n\n", $this->export($definition->getDeprecationMessage($id)));
        }

        if ($this->getProxyDumper()->isProxyCandidate($definition)) {
            $factoryCode = $asFile ? ($definition->isShared() ? "\$this->load('%s.php', false)" : '$this->factories[%2$s](false)') : '$this->%s(false)';
            $code .= $this->getProxyDumper()->getProxyFactoryCode($definition, $id, sprintf($factoryCode, $methodName, $this->doExport($id)));
        }

        $code .= $this->addServiceInclude($id, $definition);
        $code .= $this->addInlineService($id, $definition);

        if ($asFile) {
            $code = implode("\n", array_map(function ($line) { return $line ? substr($line, 8) : $line; }, explode("\n", $code)));
        } else {
            $code .= "    }\n";
        }

        $this->definitionVariables = $this->inlinedDefinitions = null;
        $this->referenceVariables = $this->serviceCalls = null;

        return [$file, $code];
    }

    private function addInlineVariables(string $id, Definition $definition, array $arguments, bool $forConstructor): string
    {
        $code = '';

        foreach ($arguments as $argument) {
            if (\is_array($argument)) {
                $code .= $this->addInlineVariables($id, $definition, $argument, $forConstructor);
            } elseif ($argument instanceof Reference) {
                $code .= $this->addInlineReference($id, $definition, $argument, $forConstructor);
            } elseif ($argument instanceof Definition) {
                $code .= $this->addInlineService($id, $definition, $argument, $forConstructor);
            }
        }

        return $code;
    }

    private function addInlineReference(string $id, Definition $definition, string $targetId, bool $forConstructor): string
    {
        while ($this->container->hasAlias($targetId)) {
            $targetId = (string) $this->container->getAlias($targetId);
        }

        list($callCount, $behavior) = $this->serviceCalls[$targetId];

        if ($id === $targetId) {
            return $this->addInlineService($id, $definition, $definition);
        }

        if ('service_container' === $targetId || isset($this->referenceVariables[$targetId])) {
            return '';
        }

        $hasSelfRef = isset($this->circularReferences[$id][$targetId]) && !isset($this->definitionVariables[$definition]);

        if ($hasSelfRef && !$forConstructor && !$forConstructor = !$this->circularReferences[$id][$targetId]) {
            $code = $this->addInlineService($id, $definition, $definition);
        } else {
            $code = '';
        }

        if (isset($this->referenceVariables[$targetId]) || (2 > $callCount && (!$hasSelfRef || !$forConstructor))) {
            return $code;
        }

        $name = $this->getNextVariableName();
        $this->referenceVariables[$targetId] = new Variable($name);

        $reference = ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE >= $behavior ? new Reference($targetId, $behavior) : null;
        $code .= sprintf("        \$%s = %s;\n", $name, $this->getServiceCall($targetId, $reference));

        if (!$hasSelfRef || !$forConstructor) {
            return $code;
        }

        $code .= sprintf(<<<'EOTXT'

        if (isset($this->%s[%s])) {
            return $this->%1$s[%2$s];
        }

EOTXT
            ,
            $this->container->getDefinition($id)->isPublic() ? 'services' : 'privates',
            $this->doExport($id)
        );

        return $code;
    }

    private function addInlineService(string $id, Definition $definition, Definition $inlineDef = null, bool $forConstructor = true): string
    {
        $code = '';

        if ($isSimpleInstance = $isRootInstance = null === $inlineDef) {
            foreach ($this->serviceCalls as $targetId => list($callCount, $behavior, $byConstructor)) {
                if ($byConstructor && isset($this->circularReferences[$id][$targetId]) && !$this->circularReferences[$id][$targetId]) {
                    $code .= $this->addInlineReference($id, $definition, $targetId, $forConstructor);
                }
            }
        }

        if (isset($this->definitionVariables[$inlineDef = $inlineDef ?: $definition])) {
            return $code;
        }

        $arguments = [$inlineDef->getArguments(), $inlineDef->getFactory()];

        $code .= $this->addInlineVariables($id, $definition, $arguments, $forConstructor);

        if ($arguments = array_filter([$inlineDef->getProperties(), $inlineDef->getMethodCalls(), $inlineDef->getConfigurator()])) {
            $isSimpleInstance = false;
        } elseif ($definition !== $inlineDef && 2 > $this->inlinedDefinitions[$inlineDef]) {
            return $code;
        }

        if (isset($this->definitionVariables[$inlineDef])) {
            $isSimpleInstance = false;
        } else {
            $name = $definition === $inlineDef ? 'instance' : $this->getNextVariableName();
            $this->definitionVariables[$inlineDef] = new Variable($name);
            $code .= '' !== $code ? "\n" : '';

            if ('instance' === $name) {
                $code .= $this->addServiceInstance($id, $definition, $isSimpleInstance);
            } else {
                $code .= $this->addNewInstance($inlineDef, '        $'.$name.' = ', $id);
            }

            if ('' !== $inline = $this->addInlineVariables($id, $definition, $arguments, false)) {
                $code .= "\n".$inline."\n";
            } elseif ($arguments && 'instance' === $name) {
                $code .= "\n";
            }

            $code .= $this->addServiceProperties($inlineDef, $name);
            $code .= $this->addServiceMethodCalls($inlineDef, $name, !$this->getProxyDumper()->isProxyCandidate($inlineDef) && $inlineDef->isShared() && !isset($this->singleUsePrivateIds[$id]) ? $id : null);
            $code .= $this->addServiceConfigurator($inlineDef, $name);
        }

        if ($isRootInstance && !$isSimpleInstance) {
            $code .= "\n        return \$instance;\n";
        }

        return $code;
    }

    private function addServices(array &$services = null): string
    {
        $publicServices = $privateServices = '';
        $definitions = $this->container->getDefinitions();
        ksort($definitions);
        foreach ($definitions as $id => $definition) {
            $services[$id] = $definition->isSynthetic() ? null : $this->addService($id, $definition);
        }

        foreach ($definitions as $id => $definition) {
            if (!(list($file, $code) = $services[$id]) || null !== $file) {
                continue;
            }
            if ($definition->isPublic()) {
                $publicServices .= $code;
            } elseif (!$this->isTrivialInstance($definition) || isset($this->locatedIds[$id])) {
                $privateServices .= $code;
            }
        }

        return $publicServices.$privateServices;
    }

    private function generateServiceFiles(array $services): iterable
    {
        $definitions = $this->container->getDefinitions();
        ksort($definitions);
        foreach ($definitions as $id => $definition) {
            if ((list($file, $code) = $services[$id]) && null !== $file && ($definition->isPublic() || !$this->isTrivialInstance($definition) || isset($this->locatedIds[$id]))) {
                if (!$definition->isShared()) {
                    $i = strpos($code, "\n\ninclude_once ");
                    if (false !== $i && false !== $i = strpos($code, "\n\n", 2 + $i)) {
                        $code = [substr($code, 0, 2 + $i), substr($code, 2 + $i)];
                    } else {
                        $code = ["\n", $code];
                    }
                    $code[1] = implode("\n", array_map(function ($line) { return $line ? '    '.$line : $line; }, explode("\n", $code[1])));
                    $factory = sprintf('$this->factories%s[%s]', $definition->isPublic() ? '' : "['service_container']", $this->doExport($id));
                    $lazyloadInitialization = $definition->isLazy() ? '$lazyLoad = true' : '';

                    $code[1] = sprintf("%s = function (%s) {\n%s};\n\nreturn %1\$s();\n", $factory, $lazyloadInitialization, $code[1]);
                    $code = $code[0].$code[1];
                }

                yield $file => $code;
            }
        }
    }

    private function addNewInstance(Definition $definition, string $return = '', string $id = null): string
    {
        $tail = $return ? ";\n" : '';

        if (BaseServiceLocator::class === $definition->getClass() && $definition->hasTag($this->serviceLocatorTag)) {
            $arguments = [];
            foreach ($definition->getArgument(0) as $k => $argument) {
                $arguments[$k] = $argument->getValues()[0];
            }

            return $return.$this->dumpValue(new ServiceLocatorArgument($arguments)).$tail;
        }

        $arguments = [];
        foreach ($definition->getArguments() as $value) {
            $arguments[] = $this->dumpValue($value);
        }

        if (null !== $definition->getFactory()) {
            $callable = $definition->getFactory();

            if (\is_array($callable)) {
                if (!preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $callable[1])) {
                    throw new RuntimeException(sprintf('Cannot dump definition because of invalid factory method (%s)', $callable[1] ?: 'n/a'));
                }

                if ($callable[0] instanceof Reference
                    || ($callable[0] instanceof Definition && $this->definitionVariables->contains($callable[0]))) {
                    return $return.sprintf('%s->%s(%s)', $this->dumpValue($callable[0]), $callable[1], $arguments ? implode(', ', $arguments) : '').$tail;
                }

                $class = $this->dumpValue($callable[0]);
                // If the class is a string we can optimize away
                if (0 === strpos($class, "'") && false === strpos($class, '$')) {
                    if ("''" === $class) {
                        throw new RuntimeException(sprintf('Cannot dump definition: %s service is defined to be created by a factory but is missing the service reference, did you forget to define the factory service id or class?', $id ? 'The "'.$id.'"' : 'inline'));
                    }

                    return $return.sprintf('%s::%s(%s)', $this->dumpLiteralClass($class), $callable[1], $arguments ? implode(', ', $arguments) : '').$tail;
                }

                if (0 === strpos($class, 'new ')) {
                    return $return.sprintf('(%s)->%s(%s)', $class, $callable[1], $arguments ? implode(', ', $arguments) : '').$tail;
                }

                return $return.sprintf("[%s, '%s'](%s)", $class, $callable[1], $arguments ? implode(', ', $arguments) : '').$tail;
            }

            return $return.sprintf('%s(%s)', $this->dumpLiteralClass($this->dumpValue($callable)), $arguments ? implode(', ', $arguments) : '').$tail;
        }

        if (null === $class = $definition->getClass()) {
            throw new RuntimeException('Cannot dump definitions which have no class nor factory.');
        }

        return $return.sprintf('new %s(%s)', $this->dumpLiteralClass($this->dumpValue($class)), implode(', ', $arguments)).$tail;
    }

    private function startClass(string $class, string $baseClass, ?array &$preload): string
    {
        $namespaceLine = !$this->asFiles && $this->namespace ? "\nnamespace {$this->namespace};\n" : '';

        $code = <<<EOF
<?php
$namespaceLine
use Symfony\Component\DependencyInjection\Argument\RewindableGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\ParameterBag\FrozenParameterBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/*{$this->docStar}
 * This class has been auto-generated
 * by the Symfony Dependency Injection Component.
 *
 * @final
 */
class $class extends $baseClass
{
    private \$parameters = [];

    public function __construct()
    {

EOF;
        if ($this->asFiles) {
            $code = str_replace('$parameters', "\$buildParameters;\n    private \$containerDir;\n    private \$parameters", $code);
            $code = str_replace('__construct()', '__construct(array $buildParameters = [], $containerDir = __DIR__)', $code);
            $code .= "        \$this->buildParameters = \$buildParameters;\n";
            $code .= "        \$this->containerDir = \$containerDir;\n";

            if (null !== $this->targetDirRegex) {
                $code = str_replace('$parameters', "\$targetDir;\n    private \$parameters", $code);
                $code .= '        $this->targetDir = \\dirname($containerDir);'."\n";
            }
        }

        if (Container::class !== $this->baseClass) {
            $r = $this->container->getReflectionClass($this->baseClass, false);
            if (null !== $r
                && (null !== $constructor = $r->getConstructor())
                && 0 === $constructor->getNumberOfRequiredParameters()
                && Container::class !== $constructor->getDeclaringClass()->name
            ) {
                $code .= "        parent::__construct();\n";
                $code .= "        \$this->parameterBag = null;\n\n";
            }
        }

        if ($this->container->getParameterBag()->all()) {
            $code .= "        \$this->parameters = \$this->getDefaultParameters();\n\n";
        }
        $code .= "        \$this->services = \$this->privates = [];\n";

        $code .= $this->addSyntheticIds();
        $code .= $this->addMethodMap();
        $code .= $this->asFiles && !$this->inlineFactories ? $this->addFileMap() : '';
        $code .= $this->addAliases();
        $code .= $this->addInlineRequires($preload);
        $code .= <<<EOF
    }

    public function compile(): void
    {
        throw new LogicException('You cannot compile a dumped container that was already compiled.');
    }

    public function isCompiled(): bool
    {
        return true;
    }

EOF;
        $code .= $this->addRemovedIds();

        if ($this->asFiles && !$this->inlineFactories) {
            $code .= <<<EOF

    protected function load(\$file, \$lazyLoad = true)
    {
        return require \$this->containerDir.\\DIRECTORY_SEPARATOR.\$file;
    }

EOF;
        }

        $proxyDumper = $this->getProxyDumper();
        foreach ($this->container->getDefinitions() as $definition) {
            if (!$proxyDumper->isProxyCandidate($definition)) {
                continue;
            }
            if ($this->asFiles && !$this->inlineFactories) {
                $proxyLoader = '$this->load("{$class}.php")';
            } elseif ($this->namespace || $this->inlineFactories) {
                $proxyLoader = 'class_alias(__NAMESPACE__."\\\\$class", $class, false)';
            } else {
                $proxyLoader = '';
            }
            if ($proxyLoader) {
                $proxyLoader = "class_exists(\$class, false) || {$proxyLoader};\n\n        ";
            }
            $code .= <<<EOF

    protected function createProxy(\$class, \Closure \$factory)
    {
        {$proxyLoader}return \$factory();
    }

EOF;
            break;
        }

        return $code;
    }

    private function addSyntheticIds(): string
    {
        $code = '';
        $definitions = $this->container->getDefinitions();
        ksort($definitions);
        foreach ($definitions as $id => $definition) {
            if ($definition->isSynthetic() && 'service_container' !== $id) {
                $code .= '            '.$this->doExport($id)." => true,\n";
            }
        }

        return $code ? "        \$this->syntheticIds = [\n{$code}        ];\n" : '';
    }

    private function addRemovedIds(): string
    {
        $ids = $this->container->getRemovedIds();
        foreach ($this->container->getDefinitions() as $id => $definition) {
            if (!$definition->isPublic()) {
                $ids[$id] = true;
            }
        }
        if (!$ids) {
            return '';
        }
        if ($this->asFiles) {
            $code = "require \$this->containerDir.\\DIRECTORY_SEPARATOR.'removed-ids.php'";
        } else {
            $code = '';
            $ids = array_keys($ids);
            sort($ids);
            foreach ($ids as $id) {
                if (preg_match(FileLoader::ANONYMOUS_ID_REGEXP, $id)) {
                    continue;
                }
                $code .= '            '.$this->doExport($id)." => true,\n";
            }

            $code = "[\n{$code}        ]";
        }

        return <<<EOF

    public function getRemovedIds(): array
    {
        return {$code};
    }

EOF;
    }

    private function addMethodMap(): string
    {
        $code = '';
        $definitions = $this->container->getDefinitions();
        ksort($definitions);
        foreach ($definitions as $id => $definition) {
            if (!$definition->isSynthetic() && $definition->isPublic() && (!$this->asFiles || $this->inlineFactories || $this->isHotPath($definition))) {
                $code .= '            '.$this->doExport($id).' => '.$this->doExport($this->generateMethodName($id)).",\n";
            }
        }

        $aliases = $this->container->getAliases();
        foreach ($aliases as $alias => $id) {
            if (!$id->isDeprecated()) {
                continue;
            }

            $code .= '            '.$this->doExport($alias).' => '.$this->doExport($this->generateMethodName($alias)).",\n";
        }

        return $code ? "        \$this->methodMap = [\n{$code}        ];\n" : '';
    }

    private function addFileMap(): string
    {
        $code = '';
        $definitions = $this->container->getDefinitions();
        ksort($definitions);
        foreach ($definitions as $id => $definition) {
            if (!$definition->isSynthetic() && $definition->isPublic() && !$this->isHotPath($definition)) {
                $code .= sprintf("            %s => '%s.php',\n", $this->doExport($id), $this->generateMethodName($id));
            }
        }

        return $code ? "        \$this->fileMap = [\n{$code}        ];\n" : '';
    }

    private function addAliases(): string
    {
        if (!$aliases = $this->container->getAliases()) {
            return "\n        \$this->aliases = [];\n";
        }

        $code = "        \$this->aliases = [\n";
        ksort($aliases);
        foreach ($aliases as $alias => $id) {
            if ($id->isDeprecated()) {
                continue;
            }

            $id = (string) $id;
            while (isset($aliases[$id])) {
                $id = (string) $aliases[$id];
            }
            $code .= '            '.$this->doExport($alias).' => '.$this->doExport($id).",\n";
        }

        return $code."        ];\n";
    }

    private function addDeprecatedAliases(): string
    {
        $code = '';
        $aliases = $this->container->getAliases();
        foreach ($aliases as $alias => $definition) {
            if (!$definition->isDeprecated()) {
                continue;
            }

            $public = $definition->isPublic() ? 'public' : 'private';
            $id = (string) $definition;
            $methodNameAlias = $this->generateMethodName($alias);

            $idExported = $this->export($id);
            $messageExported = $this->export($definition->getDeprecationMessage($alias));
            $code .= <<<EOF

    /*{$this->docStar}
     * Gets the $public '$alias' alias.
     *
     * @return object The "$id" service.
     */
    protected function {$methodNameAlias}()
    {
        @trigger_error($messageExported, E_USER_DEPRECATED);

        return \$this->get($idExported);
    }

EOF;
        }

        return $code;
    }

    private function addInlineRequires(?array &$preload): string
    {
        if (!$this->hotPathTag || !$this->inlineRequires) {
            return '';
        }

        $lineage = [];

        foreach ($this->container->findTaggedServiceIds($this->hotPathTag) as $id => $tags) {
            $definition = $this->container->getDefinition($id);

            if ($this->getProxyDumper()->isProxyCandidate($definition)) {
                continue;
            }

            $inlinedDefinitions = $this->getDefinitionsFromArguments([$definition]);

            foreach ($inlinedDefinitions as $def) {
                if (\is_string($class = \is_array($factory = $def->getFactory()) && \is_string($factory[0]) ? $factory[0] : $def->getClass())) {
                    $preload[$class] = $class;
                    $this->collectLineage($class, $lineage);
                }
            }
        }

        $code = '';

        foreach ($lineage as $file) {
            if (!isset($this->inlinedRequires[$file])) {
                $this->inlinedRequires[$file] = true;
                $code .= sprintf("\n            include_once %s;", $file);
            }
        }

        return $code ? sprintf("\n        \$this->privates['service_container'] = function () {%s\n        };\n", $code) : '';
    }

    private function addDefaultParametersMethod(): string
    {
        if (!$this->container->getParameterBag()->all()) {
            return '';
        }

        $php = [];
        $dynamicPhp = [];

        foreach ($this->container->getParameterBag()->all() as $key => $value) {
            if ($key !== $resolvedKey = $this->container->resolveEnvPlaceholders($key)) {
                throw new InvalidArgumentException(sprintf('Parameter name cannot use env parameters: %s.', $resolvedKey));
            }
            $export = $this->exportParameters([$value]);
            $export = explode('0 => ', substr(rtrim($export, " ]\n"), 2, -1), 2);

            if (preg_match("/\\\$this->(?:getEnv\('(?:\w++:)*+\w++'\)|targetDir\.'')/", $export[1])) {
                $dynamicPhp[$key] = sprintf('%scase %s: $value = %s; break;', $export[0], $this->export($key), $export[1]);
            } else {
                $php[] = sprintf('%s%s => %s,', $export[0], $this->export($key), $export[1]);
            }
        }
        $parameters = sprintf("[\n%s\n%s]", implode("\n", $php), str_repeat(' ', 8));

        $code = <<<'EOF'

    public function getParameter($name)
    {
        $name = (string) $name;
        if (isset($this->buildParameters[$name])) {
            return $this->buildParameters[$name];
        }

        if (!(isset($this->parameters[$name]) || isset($this->loadedDynamicParameters[$name]) || array_key_exists($name, $this->parameters))) {
            throw new InvalidArgumentException(sprintf('The parameter "%s" must be defined.', $name));
        }
        if (isset($this->loadedDynamicParameters[$name])) {
            return $this->loadedDynamicParameters[$name] ? $this->dynamicParameters[$name] : $this->getDynamicParameter($name);
        }

        return $this->parameters[$name];
    }

    public function hasParameter($name): bool
    {
        $name = (string) $name;
        if (isset($this->buildParameters[$name])) {
            return true;
        }

        return isset($this->parameters[$name]) || isset($this->loadedDynamicParameters[$name]) || array_key_exists($name, $this->parameters);
    }

    public function setParameter($name, $value): void
    {
        throw new LogicException('Impossible to call set() on a frozen ParameterBag.');
    }

    public function getParameterBag(): ParameterBagInterface
    {
        if (null === $this->parameterBag) {
            $parameters = $this->parameters;
            foreach ($this->loadedDynamicParameters as $name => $loaded) {
                $parameters[$name] = $loaded ? $this->dynamicParameters[$name] : $this->getDynamicParameter($name);
            }
            foreach ($this->buildParameters as $name => $value) {
                $parameters[$name] = $value;
            }
            $this->parameterBag = new FrozenParameterBag($parameters);
        }

        return $this->parameterBag;
    }

EOF;
        if (!$this->asFiles) {
            $code = preg_replace('/^.*buildParameters.*\n.*\n.*\n/m', '', $code);
        }

        if ($dynamicPhp) {
            $loadedDynamicParameters = $this->exportParameters(array_combine(array_keys($dynamicPhp), array_fill(0, \count($dynamicPhp), false)), '', 8);
            $getDynamicParameter = <<<'EOF'
        switch ($name) {
%s
            default: throw new InvalidArgumentException(sprintf('The dynamic parameter "%%s" must be defined.', $name));
        }
        $this->loadedDynamicParameters[$name] = true;

        return $this->dynamicParameters[$name] = $value;
EOF;
            $getDynamicParameter = sprintf($getDynamicParameter, implode("\n", $dynamicPhp));
        } else {
            $loadedDynamicParameters = '[]';
            $getDynamicParameter = str_repeat(' ', 8).'throw new InvalidArgumentException(sprintf(\'The dynamic parameter "%s" must be defined.\', $name));';
        }

        $code .= <<<EOF

    private \$loadedDynamicParameters = {$loadedDynamicParameters};
    private \$dynamicParameters = [];

    private function getDynamicParameter(string \$name)
    {
{$getDynamicParameter}
    }

    protected function getDefaultParameters(): array
    {
        return $parameters;
    }

EOF;

        return $code;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function exportParameters(array $parameters, string $path = '', int $indent = 12): string
    {
        $php = [];
        foreach ($parameters as $key => $value) {
            if (\is_array($value)) {
                $value = $this->exportParameters($value, $path.'/'.$key, $indent + 4);
            } elseif ($value instanceof ArgumentInterface) {
                throw new InvalidArgumentException(sprintf('You cannot dump a container with parameters that contain special arguments. "%s" found in "%s".', \get_class($value), $path.'/'.$key));
            } elseif ($value instanceof Variable) {
                throw new InvalidArgumentException(sprintf('You cannot dump a container with parameters that contain variable references. Variable "%s" found in "%s".', $value, $path.'/'.$key));
            } elseif ($value instanceof Definition) {
                throw new InvalidArgumentException(sprintf('You cannot dump a container with parameters that contain service definitions. Definition for "%s" found in "%s".', $value->getClass(), $path.'/'.$key));
            } elseif ($value instanceof Reference) {
                throw new InvalidArgumentException(sprintf('You cannot dump a container with parameters that contain references to other services (reference to service "%s" found in "%s").', $value, $path.'/'.$key));
            } elseif ($value instanceof Expression) {
                throw new InvalidArgumentException(sprintf('You cannot dump a container with parameters that contain expressions. Expression "%s" found in "%s".', $value, $path.'/'.$key));
            } else {
                $value = $this->export($value);
            }

            $php[] = sprintf('%s%s => %s,', str_repeat(' ', $indent), $this->export($key), $value);
        }

        return sprintf("[\n%s\n%s]", implode("\n", $php), str_repeat(' ', $indent - 4));
    }

    private function endClass(): string
    {
        if ($this->addThrow) {
            return <<<'EOF'

    protected function throw($message)
    {
        throw new RuntimeException($message);
    }
}

EOF;
        }

        return <<<'EOF'
}

EOF;
    }

    private function wrapServiceConditionals($value, string $code): string
    {
        if (!$condition = $this->getServiceConditionals($value)) {
            return $code;
        }

        // re-indent the wrapped code
        $code = implode("\n", array_map(function ($line) { return $line ? '    '.$line : $line; }, explode("\n", $code)));

        return sprintf("        if (%s) {\n%s        }\n", $condition, $code);
    }

    private function getServiceConditionals($value): string
    {
        $conditions = [];
        foreach (ContainerBuilder::getInitializedConditionals($value) as $service) {
            if (!$this->container->hasDefinition($service)) {
                return 'false';
            }
            $conditions[] = sprintf('isset($this->%s[%s])', $this->container->getDefinition($service)->isPublic() ? 'services' : 'privates', $this->doExport($service));
        }
        foreach (ContainerBuilder::getServiceConditionals($value) as $service) {
            if ($this->container->hasDefinition($service) && !$this->container->getDefinition($service)->isPublic()) {
                continue;
            }

            $conditions[] = sprintf('$this->has(%s)', $this->doExport($service));
        }

        if (!$conditions) {
            return '';
        }

        return implode(' && ', $conditions);
    }

    private function getDefinitionsFromArguments(array $arguments, \SplObjectStorage $definitions = null, array &$calls = [], bool $byConstructor = null): \SplObjectStorage
    {
        if (null === $definitions) {
            $definitions = new \SplObjectStorage();
        }

        foreach ($arguments as $argument) {
            if (\is_array($argument)) {
                $this->getDefinitionsFromArguments($argument, $definitions, $calls, $byConstructor);
            } elseif ($argument instanceof Reference) {
                $id = (string) $argument;

                while ($this->container->hasAlias($id)) {
                    $id = (string) $this->container->getAlias($id);
                }

                if (!isset($calls[$id])) {
                    $calls[$id] = [0, $argument->getInvalidBehavior(), $byConstructor];
                } else {
                    $calls[$id][1] = min($calls[$id][1], $argument->getInvalidBehavior());
                }

                ++$calls[$id][0];
            } elseif (!$argument instanceof Definition) {
                // no-op
            } elseif (isset($definitions[$argument])) {
                $definitions[$argument] = 1 + $definitions[$argument];
            } else {
                $definitions[$argument] = 1;
                $arguments = [$argument->getArguments(), $argument->getFactory()];
                $this->getDefinitionsFromArguments($arguments, $definitions, $calls, null === $byConstructor || $byConstructor);
                $arguments = [$argument->getProperties(), $argument->getMethodCalls(), $argument->getConfigurator()];
                $this->getDefinitionsFromArguments($arguments, $definitions, $calls, null !== $byConstructor && $byConstructor);
            }
        }

        return $definitions;
    }

    /**
     * @throws RuntimeException
     */
    private function dumpValue($value, bool $interpolate = true): string
    {
        if (\is_array($value)) {
            if ($value && $interpolate && false !== $param = array_search($value, $this->container->getParameterBag()->all(), true)) {
                return $this->dumpValue("%$param%");
            }
            $code = [];
            foreach ($value as $k => $v) {
                $code[] = sprintf('%s => %s', $this->dumpValue($k, $interpolate), $this->dumpValue($v, $interpolate));
            }

            return sprintf('[%s]', implode(', ', $code));
        } elseif ($value instanceof ArgumentInterface) {
            $scope = [$this->definitionVariables, $this->referenceVariables];
            $this->definitionVariables = $this->referenceVariables = null;

            try {
                if ($value instanceof ServiceClosureArgument) {
                    $value = $value->getValues()[0];
                    $code = $this->dumpValue($value, $interpolate);

                    $returnedType = '';
                    if ($value instanceof TypedReference) {
                        $returnedType = sprintf(': %s\%s', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE >= $value->getInvalidBehavior() ? '' : '?', $value->getType());
                    }

                    $code = sprintf('return %s;', $code);

                    return sprintf("function ()%s {\n            %s\n        }", $returnedType, $code);
                }

                if ($value instanceof IteratorArgument) {
                    $operands = [0];
                    $code = [];
                    $code[] = 'new RewindableGenerator(function () {';

                    if (!$values = $value->getValues()) {
                        $code[] = '            return new \EmptyIterator();';
                    } else {
                        $countCode = [];
                        $countCode[] = 'function () {';

                        foreach ($values as $k => $v) {
                            ($c = $this->getServiceConditionals($v)) ? $operands[] = "(int) ($c)" : ++$operands[0];
                            $v = $this->wrapServiceConditionals($v, sprintf("        yield %s => %s;\n", $this->dumpValue($k, $interpolate), $this->dumpValue($v, $interpolate)));
                            foreach (explode("\n", $v) as $v) {
                                if ($v) {
                                    $code[] = '    '.$v;
                                }
                            }
                        }

                        $countCode[] = sprintf('            return %s;', implode(' + ', $operands));
                        $countCode[] = '        }';
                    }

                    $code[] = sprintf('        }, %s)', \count($operands) > 1 ? implode("\n", $countCode) : $operands[0]);

                    return implode("\n", $code);
                }

                if ($value instanceof ServiceLocatorArgument) {
                    $serviceMap = '';
                    $serviceTypes = '';
                    foreach ($value->getValues() as $k => $v) {
                        if (!$v) {
                            continue;
                        }
                        $definition = $this->container->findDefinition($id = (string) $v);
                        $load = !($definition->hasErrors() && $e = $definition->getErrors()) ? $this->asFiles && !$this->inlineFactories && !$this->isHotPath($definition) : reset($e);
                        $serviceMap .= sprintf("\n            %s => [%s, %s, %s, %s],",
                            $this->export($k),
                            $this->export($definition->isShared() ? ($definition->isPublic() ? 'services' : 'privates') : false),
                            $this->doExport($id),
                            $this->export(ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE !== $v->getInvalidBehavior() && !\is_string($load) ? $this->generateMethodName($id).($load ? '.php' : '') : null),
                            $this->export($load)
                        );
                        $serviceTypes .= sprintf("\n            %s => %s,", $this->export($k), $this->export($v instanceof TypedReference ? $v->getType() : '?'));
                        $this->locatedIds[$id] = true;
                    }
                    $this->addGetService = true;

                    return sprintf('new \%s($this->getService, [%s%s], [%s%s])', ServiceLocator::class, $serviceMap, $serviceMap ? "\n        " : '', $serviceTypes, $serviceTypes ? "\n        " : '');
                }
            } finally {
                list($this->definitionVariables, $this->referenceVariables) = $scope;
            }
        } elseif ($value instanceof Definition) {
            if ($value->hasErrors() && $e = $value->getErrors()) {
                $this->addThrow = true;

                return sprintf('$this->throw(%s)', $this->export(reset($e)));
            }
            if (null !== $this->definitionVariables && $this->definitionVariables->contains($value)) {
                return $this->dumpValue($this->definitionVariables[$value], $interpolate);
            }
            if ($value->getMethodCalls()) {
                throw new RuntimeException('Cannot dump definitions which have method calls.');
            }
            if ($value->getProperties()) {
                throw new RuntimeException('Cannot dump definitions which have properties.');
            }
            if (null !== $value->getConfigurator()) {
                throw new RuntimeException('Cannot dump definitions which have a configurator.');
            }

            return $this->addNewInstance($value);
        } elseif ($value instanceof Variable) {
            return '$'.$value;
        } elseif ($value instanceof Reference) {
            $id = (string) $value;

            while ($this->container->hasAlias($id)) {
                $id = (string) $this->container->getAlias($id);
            }

            if (null !== $this->referenceVariables && isset($this->referenceVariables[$id])) {
                return $this->dumpValue($this->referenceVariables[$id], $interpolate);
            }

            return $this->getServiceCall($id, $value);
        } elseif ($value instanceof Expression) {
            return $this->getExpressionLanguage()->compile((string) $value, ['this' => 'container']);
        } elseif ($value instanceof Parameter) {
            return $this->dumpParameter($value);
        } elseif (true === $interpolate && \is_string($value)) {
            if (preg_match('/^%([^%]+)%$/', $value, $match)) {
                // we do this to deal with non string values (Boolean, integer, ...)
                // the preg_replace_callback converts them to strings
                return $this->dumpParameter($match[1]);
            } else {
                $replaceParameters = function ($match) {
                    return "'.".$this->dumpParameter($match[2]).".'";
                };

                $code = str_replace('%%', '%', preg_replace_callback('/(?<!%)(%)([^%]+)\1/', $replaceParameters, $this->export($value)));

                return $code;
            }
        } elseif (\is_object($value) || \is_resource($value)) {
            throw new RuntimeException('Unable to dump a service container if a parameter is an object or a resource.');
        }

        return $this->export($value);
    }

    /**
     * Dumps a string to a literal (aka PHP Code) class value.
     *
     * @throws RuntimeException
     */
    private function dumpLiteralClass(string $class): string
    {
        if (false !== strpos($class, '$')) {
            return sprintf('${($_ = %s) && false ?: "_"}', $class);
        }
        if (0 !== strpos($class, "'") || !preg_match('/^\'(?:\\\{2})?[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(?:\\\{2}[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)*\'$/', $class)) {
            throw new RuntimeException(sprintf('Cannot dump definition because of invalid class name (%s)', $class ?: 'n/a'));
        }

        $class = substr(str_replace('\\\\', '\\', $class), 1, -1);

        return 0 === strpos($class, '\\') ? $class : '\\'.$class;
    }

    private function dumpParameter(string $name): string
    {
        if ($this->container->hasParameter($name)) {
            $value = $this->container->getParameter($name);
            $dumpedValue = $this->dumpValue($value, false);

            if (!$value || !\is_array($value)) {
                return $dumpedValue;
            }

            if (!preg_match("/\\\$this->(?:getEnv\('(?:\w++:)*+\w++'\)|targetDir\.'')/", $dumpedValue)) {
                return sprintf('$this->parameters[%s]', $this->doExport($name));
            }
        }

        return sprintf('$this->getParameter(%s)', $this->doExport($name));
    }

    private function getServiceCall(string $id, Reference $reference = null): string
    {
        while ($this->container->hasAlias($id)) {
            $id = (string) $this->container->getAlias($id);
        }

        if ('service_container' === $id) {
            return '$this';
        }

        if ($this->container->hasDefinition($id) && $definition = $this->container->getDefinition($id)) {
            if ($definition->isSynthetic()) {
                $code = sprintf('$this->get(%s%s)', $this->doExport($id), null !== $reference ? ', '.$reference->getInvalidBehavior() : '');
            } elseif (null !== $reference && ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE === $reference->getInvalidBehavior()) {
                $code = 'null';
                if (!$definition->isShared()) {
                    return $code;
                }
            } elseif ($this->isTrivialInstance($definition)) {
                if ($definition->hasErrors() && $e = $definition->getErrors()) {
                    $this->addThrow = true;

                    return sprintf('$this->throw(%s)', $this->export(reset($e)));
                }
                $code = $this->addNewInstance($definition, '', $id);
                if ($definition->isShared() && !isset($this->singleUsePrivateIds[$id])) {
                    $code = sprintf('$this->%s[%s] = %s', $definition->isPublic() ? 'services' : 'privates', $this->doExport($id), $code);
                }
                $code = "($code)";
            } elseif ($this->asFiles && !$this->inlineFactories && !$this->isHotPath($definition)) {
                $code = sprintf("\$this->load('%s.php')", $this->generateMethodName($id));
                if (!$definition->isShared()) {
                    $factory = sprintf('$this->factories%s[%s]', $definition->isPublic() ? '' : "['service_container']", $this->doExport($id));
                    $code = sprintf('(isset(%s) ? %1$s() : %s)', $factory, $code);
                }
            } else {
                $code = sprintf('$this->get(%s%s)', $this->doExport($id), null !== $reference ? ', '.$reference->getInvalidBehavior() : '');
            }
            if ($definition->isShared() && !isset($this->singleUsePrivateIds[$id])) {
                $code = sprintf('($this->%s[%s] ?? %s)', $definition->isPublic() ? 'services' : 'privates', $this->doExport($id), $code);
            }

            return $code;
        }
        if (null !== $reference && ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE === $reference->getInvalidBehavior()) {
            return 'null';
        }
        if (null !== $reference && ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE < $reference->getInvalidBehavior()) {
            $code = sprintf('$this->get(%s, /* ContainerInterface::NULL_ON_INVALID_REFERENCE */ %d)', $this->doExport($id), ContainerInterface::NULL_ON_INVALID_REFERENCE);
        } else {
            $code = sprintf('$this->get(%s)', $this->doExport($id));
        }

        return sprintf('($this->services[%s] ?? %s)', $this->doExport($id), $code);
    }

    /**
     * Initializes the method names map to avoid conflicts with the Container methods.
     */
    private function initializeMethodNamesMap(string $class)
    {
        $this->serviceIdToMethodNameMap = [];
        $this->usedMethodNames = [];

        if ($reflectionClass = $this->container->getReflectionClass($class)) {
            foreach ($reflectionClass->getMethods() as $method) {
                $this->usedMethodNames[strtolower($method->getName())] = true;
            }
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function generateMethodName(string $id): string
    {
        if (isset($this->serviceIdToMethodNameMap[$id])) {
            return $this->serviceIdToMethodNameMap[$id];
        }

        $i = strrpos($id, '\\');
        $name = Container::camelize(false !== $i && isset($id[1 + $i]) ? substr($id, 1 + $i) : $id);
        $name = preg_replace('/[^a-zA-Z0-9_\x7f-\xff]/', '', $name);
        $methodName = 'get'.$name.'Service';
        $suffix = 1;

        while (isset($this->usedMethodNames[strtolower($methodName)])) {
            ++$suffix;
            $methodName = 'get'.$name.$suffix.'Service';
        }

        $this->serviceIdToMethodNameMap[$id] = $methodName;
        $this->usedMethodNames[strtolower($methodName)] = true;

        return $methodName;
    }

    private function getNextVariableName(): string
    {
        $firstChars = self::FIRST_CHARS;
        $firstCharsLength = \strlen($firstChars);
        $nonFirstChars = self::NON_FIRST_CHARS;
        $nonFirstCharsLength = \strlen($nonFirstChars);

        while (true) {
            $name = '';
            $i = $this->variableCount;

            if ('' === $name) {
                $name .= $firstChars[$i % $firstCharsLength];
                $i = (int) ($i / $firstCharsLength);
            }

            while ($i > 0) {
                --$i;
                $name .= $nonFirstChars[$i % $nonFirstCharsLength];
                $i = (int) ($i / $nonFirstCharsLength);
            }

            ++$this->variableCount;

            // check that the name is not reserved
            if (\in_array($name, $this->reservedVariables, true)) {
                continue;
            }

            return $name;
        }
    }

    private function getExpressionLanguage(): ExpressionLanguage
    {
        if (null === $this->expressionLanguage) {
            if (!class_exists('Symfony\Component\ExpressionLanguage\ExpressionLanguage')) {
                throw new LogicException('Unable to use expressions as the Symfony ExpressionLanguage component is not installed.');
            }
            $providers = $this->container->getExpressionLanguageProviders();
            $this->expressionLanguage = new ExpressionLanguage(null, $providers, function ($arg) {
                $id = '""' === substr_replace($arg, '', 1, -1) ? stripcslashes(substr($arg, 1, -1)) : null;

                if (null !== $id && ($this->container->hasAlias($id) || $this->container->hasDefinition($id))) {
                    return $this->getServiceCall($id);
                }

                return sprintf('$this->get(%s)', $arg);
            });

            if ($this->container->isTrackingResources()) {
                foreach ($providers as $provider) {
                    $this->container->addObjectResource($provider);
                }
            }
        }

        return $this->expressionLanguage;
    }

    private function isHotPath(Definition $definition): bool
    {
        return $this->hotPathTag && $definition->hasTag($this->hotPathTag) && !$definition->isDeprecated();
    }

    private function isSingleUsePrivateNode(ServiceReferenceGraphNode $node): bool
    {
        if ($node->getValue()->isPublic()) {
            return false;
        }
        $ids = [];
        foreach ($node->getInEdges() as $edge) {
            if (!$value = $edge->getSourceNode()->getValue()) {
                continue;
            }
            if ($edge->isLazy() || !$value instanceof Definition || !$value->isShared()) {
                return false;
            }
            $ids[$edge->getSourceNode()->getId()] = true;
        }

        return 1 === \count($ids);
    }

    /**
     * @return mixed
     */
    private function export($value)
    {
        if (null !== $this->targetDirRegex && \is_string($value) && preg_match($this->targetDirRegex, $value, $matches, PREG_OFFSET_CAPTURE)) {
            $prefix = $matches[0][1] ? $this->doExport(substr($value, 0, $matches[0][1]), true).'.' : '';
            $suffix = $matches[0][1] + \strlen($matches[0][0]);
            $suffix = isset($value[$suffix]) ? '.'.$this->doExport(substr($value, $suffix), true) : '';
            $dirname = $this->asFiles ? '$this->containerDir' : '__DIR__';
            $offset = 1 + $this->targetDirMaxMatches - \count($matches);

            if (0 < $offset) {
                $dirname = sprintf('\dirname(__DIR__, %d)', $offset + (int) $this->asFiles);
            } elseif ($this->asFiles) {
                $dirname = "\$this->targetDir.''"; // empty string concatenation on purpose
            }

            if ($prefix || $suffix) {
                return sprintf('(%s%s%s)', $prefix, $dirname, $suffix);
            }

            return $dirname;
        }

        return $this->doExport($value, true);
    }

    /**
     * @return mixed
     */
    private function doExport($value, bool $resolveEnv = false)
    {
        $shouldCacheValue = $resolveEnv && \is_string($value);
        if ($shouldCacheValue && isset($this->exportedVariables[$value])) {
            return $this->exportedVariables[$value];
        }
        if (\is_string($value) && false !== strpos($value, "\n")) {
            $cleanParts = explode("\n", $value);
            $cleanParts = array_map(function ($part) { return var_export($part, true); }, $cleanParts);
            $export = implode('."\n".', $cleanParts);
        } else {
            $export = var_export($value, true);
        }

        if ($resolveEnv && "'" === $export[0] && $export !== $resolvedExport = $this->container->resolveEnvPlaceholders($export, "'.\$this->getEnv('string:%s').'")) {
            $export = $resolvedExport;
            if (".''" === substr($export, -3)) {
                $export = substr($export, 0, -3);
                if ("'" === $export[1]) {
                    $export = substr_replace($export, '', 18, 7);
                }
            }
            if ("'" === $export[1]) {
                $export = substr($export, 3);
            }
        }

        if ($shouldCacheValue) {
            $this->exportedVariables[$value] = $export;
        }

        return $export;
    }

    private function getAutoloadFile(): ?string
    {
        if (null === $this->targetDirRegex) {
            return null;
        }

        foreach (spl_autoload_functions() as $autoloader) {
            if (!\is_array($autoloader)) {
                continue;
            }

            if ($autoloader[0] instanceof DebugClassLoader || $autoloader[0] instanceof LegacyDebugClassLoader) {
                $autoloader = $autoloader[0]->getClassLoader();
            }

            if (!\is_array($autoloader) || !$autoloader[0] instanceof ClassLoader || !$autoloader[0]->findFile(__CLASS__)) {
                continue;
            }

            foreach (get_declared_classes() as $class) {
                if (0 === strpos($class, 'ComposerAutoloaderInit') && $class::getLoader() === $autoloader[0]) {
                    $file = \dirname((new \ReflectionClass($class))->getFileName(), 2).'/autoload.php';

                    if (preg_match($this->targetDirRegex.'A', $file)) {
                        return $file;
                    }
                }
            }
        }

        return null;
    }
}