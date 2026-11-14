<?php

declare(strict_types=1);

namespace Doctrine\StaticAnalysis\Tools\Pagination;

use Doctrine\ORM\Tools\Pagination\WindowPage;

/** @template-covariant T of object */
abstract class PageFactory
{
    /** @var class-string<T> */
    private $class;

    /** @param class-string<T> $class */
    final public function __construct(string $class)
    {
        $this->class = $class;
    }

    /** @return class-string<T> */
    public function getClass(): string
    {
        return $this->class;
    }

    /** @phpstan-return WindowPage<T> */
    abstract public function createPage(): WindowPage;
}

interface Animal
{
}

class Cat implements Animal
{
}

/** @param WindowPage<Animal> $page */
function getFirstAnimal(WindowPage $page): Animal|null
{
    foreach ($page as $result) {
        return $result;
    }

    return null;
}

/** @param PageFactory<Cat> $catPageFactory */
function test(PageFactory $catPageFactory): Animal|null
{
    return getFirstAnimal($catPageFactory->createPage());
}
