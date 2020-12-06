<?php

namespace Doctrine\Tests\ORM;

use Doctrine\ORM\Internal\CommitOrderCalculator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Tests\OrmTestCase;
use function array_search;

/**
 * Tests of the commit order calculation.
 *
 * IMPORTANT: When writing tests here consider that a lot of graph constellations
 * can have many valid orderings, so you may want to build a graph that has only
 * 1 valid order to simplify your tests.
 */
class CommitOrderCalculatorTest extends OrmTestCase
{
    private $_calc;

    protected function setUp() : void
    {
        $this->_calc = new CommitOrderCalculator();
    }

    public function testCommitOrdering1()
    {
        $class1 = new ClassMetadata(NodeClass1::class);
        $class2 = new ClassMetadata(NodeClass2::class);
        $class3 = new ClassMetadata(NodeClass3::class);
        $class4 = new ClassMetadata(NodeClass4::class);
        $class5 = new ClassMetadata(NodeClass5::class);

        $this->_calc->addNode($class1->name, $class1);
        $this->_calc->addNode($class2->name, $class2);
        $this->_calc->addNode($class3->name, $class3);
        $this->_calc->addNode($class4->name, $class4);
        $this->_calc->addNode($class5->name, $class5);

        $this->_calc->addDependency($class1->name, $class2->name, 1);
        $this->_calc->addDependency($class2->name, $class3->name, 1);
        $this->_calc->addDependency($class3->name, $class4->name, 1);
        $this->_calc->addDependency($class5->name, $class1->name, 1);

        $sorted = $this->_calc->sort();

        // There is only 1 valid ordering for this constellation
        $correctOrder = [$class5, $class1, $class2, $class3, $class4];

        $this->assertSame($correctOrder, $sorted);
    }

    public function testCommitOrdering2()
    {
        $class1 = new ClassMetadata(NodeClass1::class);
        $class2 = new ClassMetadata(NodeClass2::class);

        $this->_calc->addNode($class1->name, $class1);
        $this->_calc->addNode($class2->name, $class2);

        $this->_calc->addDependency($class1->name, $class2->name, 0);
        $this->_calc->addDependency($class2->name, $class1->name, 1);

        $sorted = $this->_calc->sort();

        // There is only 1 valid ordering for this constellation
        $correctOrder = [$class2, $class1];

        $this->assertSame($correctOrder, $sorted);
    }

    public function testCommitOrdering3()
    {
        // this test corresponds to the GH7259Test::testPersistFileBeforeVersion functional test
        $class1 = new ClassMetadata(NodeClass1::class);
        $class2 = new ClassMetadata(NodeClass2::class);
        $class3 = new ClassMetadata(NodeClass3::class);
        $class4 = new ClassMetadata(NodeClass4::class);

        $this->_calc->addNode($class1->name, $class1);
        $this->_calc->addNode($class2->name, $class2);
        $this->_calc->addNode($class3->name, $class3);
        $this->_calc->addNode($class4->name, $class4);

        $this->_calc->addDependency($class4->name, $class1->name, 1);
        $this->_calc->addDependency($class1->name, $class2->name, 1);
        $this->_calc->addDependency($class4->name, $class3->name, 1);
        $this->_calc->addDependency($class1->name, $class4->name, 0);

        $sorted = $this->_calc->sort();

        // There is only multiple valid ordering for this constellation, but
        // the class4, class1, class2 ordering is important to break the cycle
        // on the nullable link.
        $correctOrders = [
            [$class4, $class1, $class2, $class3],
            [$class4, $class1, $class3, $class2],
            [$class4, $class3, $class1, $class2],
        ];

        // We want to perform a strict comparison of the array
        $this->assertContains($sorted, $correctOrders, '', false, true);
    }

    public function testCommitOrdering4()
    {
        // testing Fixed commit order
        $class1 = new ClassMetadata(NodeClass1::class);
        $class2 = new ClassMetadata(NodeClass2::class);
        $class3 = new ClassMetadata(NodeClass3::class);
        $class4 = new ClassMetadata(NodeClass4::class);

        $this->_calc->addNode($class1->name, $class1);
        $this->_calc->addNode($class2->name, $class2);
        $this->_calc->addNode($class3->name, $class3);
        $this->_calc->addNode($class4->name, $class4);

        $this->_calc->addDependency($class1->name, $class2->name, 0);
        $this->_calc->addDependency($class2->name, $class3->name, 0);
        $this->_calc->addDependency($class3->name, $class1->name, 0);
        $this->_calc->addDependency($class2->name, $class4->name, 0);
        $this->_calc->addDependency($class4->name, $class1->name, 1);

        /*
               +---------+
               |         |
            +--+ Class 4 <---+
            |  |         |   |
          1 |  +---------+   | 0
            |                |
            v                |
        +---+-----+    +-----+---+
        |         |    |         |
        | Class 1 +--->+ Class 2 |
        |         | 0  |         |
        +---+-----+    +-----+---+
            ^                |
            |                |
          0 |                | 0
            |  +---------+   |
            |  |         |   |
            +--+ Class 3 +<--+
               |         |
               +---------+
        */

        $sorted = $this->_calc->sort();
        $index1 = array_search($class1, $sorted, true);
        $index4 = array_search($class4, $sorted, true);

        $this->assertLessThan($index1, $index4);
    }

    public function testCommitOrdering5()
    {
        // testing Fixed commit order with multiple edges of different priority
        // between two certain nodes
        $class1 = new ClassMetadata(NodeClass1::class);
        $class2 = new ClassMetadata(NodeClass2::class);

        $this->_calc->addNode($class1->name, $class1);
        $this->_calc->addNode($class2->name, $class2);

        $this->_calc->addDependency($class1->name, $class2->name, 0);
        $this->_calc->addDependency($class2->name, $class1->name, 1);
        $this->_calc->addDependency($class2->name, $class1->name, 0);

        /*
                     1
            +----------------+
            v                |
        +---+-----+    +-----+---+
        |         |    |         |
        | Class 1 +--->+ Class 2 |
        |         | 0  |         |
        +---+-----+    +-----+---+
            ^                |
            +----------------+
                     0
        */

        $sorted = $this->_calc->sort();
        $index1 = array_search($class1, $sorted, true);
        $index2 = array_search($class2, $sorted, true);

        $this->assertLessThan($index1, $index2);
    }
}

class NodeClass1 {}
class NodeClass2 {}
class NodeClass3 {}
class NodeClass4 {}
class NodeClass5 {}
