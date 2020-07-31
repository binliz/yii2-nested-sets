# yii2-nestedSets
Package to use Nested Sets in Yii2

use NestedSetsTrait;

(new model)->startTreeGeneration()
            ->setOwner($owner)
            ->setNodes($employees)
            ->setParentCriteria('parent')
            ->fixTree();
