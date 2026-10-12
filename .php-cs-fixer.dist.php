<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
	->in([__DIR__ . '/src', __DIR__ . '/tests'])
	->append([__FILE__]);

return (new Config())
	->setRiskyAllowed(true)
	->setIndent("\t")
	->setLineEnding("\n")
	->setFinder($finder)
	->setRules([
		'@PER-CS' => true,
		'@PHP81Migration' => true,
		'declare_strict_types' => true,
		'global_namespace_import' => [
			'import_classes' => true,
			'import_constants' => true,
			'import_functions' => true,
		],
		'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
		'no_unused_imports' => true,
		'ordered_imports' => [
			'imports_order' => ['class', 'function', 'const'],
			'sort_algorithm' => 'alpha',
		],
		'phpdoc_trim' => true,
		'single_quote' => true,
	]);
