<?php

namespace binliz\NestedSets;

use common\traits\ModelValidationErrorsTrait;
use yii\db\ActiveRecord;
use yii\db\Connection;

trait NestedSetsTrait
{
    private $treeBroken = false;
    private $nodeIdList = [];
    private $parentCriteria = "parent_id";
    private $globalParentNode = null;
    private $companyId = null;

    public function startTreeGeneration(): self
    {
        $this->treeBroken = true;

        return $this;
    }

    /**
     * This method take Array of ActiveRecord nodes to compute tree
     *
     * @param array $nodes
     *
     * @return $this
     */
    public function setNodes(array $nodes): self
    {
        $this->brokeTree();

        return $this;
    }

    /** These method set all attributes to 0 */
    private function brokeTree()
    {
        $i = 0;
        foreach ($this->nodeIdList as $node) {
            $this->getDb()->createCommand()->update(
                self::tableName(),
                [
                    $this->leftAttribute => ++$i,
                    $this->rightAttribute => ++$i,
                    $this->depthAttribute => 0,
                    '_tree' => $this->globalParentNode,
                ],
                ['=', 'id', $node]
            )->query();
        }
    }

    /**
     * This method set criteria to find parents
     *
     * @param string $field
     *
     * @return $this
     */
    public function setParentCriteria(string $field): self
    {
        $this->parentCriteria = $field;

        return $this;
    }

    /**
     * This method set owner for all nodes
     *
     * @param ActiveRecord $owner
     *
     * @return $this
     */
    public function setOwner(ActiveRecord $owner): self
    {
        $this->globalParentNode = $owner->id;
        $this->companyId = $owner->getAttribute('companyId');
        $db = $this->getDb();
        $nodes = $db->createCommand('SELECT id from ' . self::tableName() . ' where companyId=' . $this->companyId)
            ->queryAll();
        $this->nodeIdList = array_column($nodes, 'id');

        return $this;
    }

    private function sign($data)
    {
        return $data >= 0 ? '+' : '';
    }

    private function updateRightNodes($left, $delta)
    {
        /** @var Connection $db */
        $db = $this->getDb();
        $query = $db->createCommand(
            'UPDATE ' . self::tableName() . 'SET ' .
            $this->rightAttribute . ' = ' . $this->rightAttribute . $this->sign($delta) . $delta . ', ' .
            $this->leftAttribute . ' = IF(' . $this->leftAttribute . '>' . $left . ',' .
            $this->leftAttribute . $this->sign($delta) . $delta .
            ',' . $this->leftAttribute . ') ' .
            'WHERE ' . $this->rightAttribute . '>' . $left .
            'AND companyId = ' . $this->companyId
        );
        $query->execute();
    }

    public function movePermanentNode($nodeId, $parentId)
    {
        $node_info = $this->getNodeInfo($nodeId);
        $left_id = $node_info[$this->leftAttribute];
        $right_id = $node_info[$this->rightAttribute];
        $level = $node_info[$this->depthAttribute];
        $parent_id = $node_info['parent_id'];

        $node_parent_info = $this->getNodeInfo($parentId);

        $left_idp = $node_parent_info[$this->leftAttribute];
        $right_idp = $node_parent_info[$this->rightAttribute];
        $levelp = $node_parent_info[$this->depthAttribute];

        $sql = 'UPDATE ' . self::tableName() . ' SET ';
        if ($left_idp < $left_id && $right_idp > $right_id && $levelp < $level - 1) {
            $sql .= $this->depthAttribute . ' = CASE WHEN ' . $this->leftAttribute . ' BETWEEN ' . $left_id . ' AND ' . $right_id . ' THEN ' . $this->depthAttribute . sprintf(
                    '%+d',
                    -($level - 1) + $levelp
                ) . ' ELSE ' . $this->depthAttribute . ' END, ';
            $sql .= $this->rightAttribute . ' = CASE WHEN ' . $this->rightAttribute . ' BETWEEN ' . ($right_id + 1) . ' AND ' . ($right_idp - 1) . ' THEN ' . $this->rightAttribute . '-' . ($right_id - $left_id + 1) . ' ';
            $sql .= 'WHEN ' . $this->leftAttribute . ' BETWEEN ' . $left_id . ' AND ' . $right_id . ' THEN ' . $this->rightAttribute . '+' . ((($right_idp - $right_id - $level + $levelp) / 2) * 2 + $level - $levelp - 1) . ' ELSE ' . $this->rightAttribute . ' END, ';
            $sql .= $this->leftAttribute . ' = CASE WHEN ' . $this->leftAttribute . ' BETWEEN ' . ($right_id + 1) . ' AND ' . ($right_idp - 1) . ' THEN ' . $this->leftAttribute . '-' . ($right_id - $left_id + 1) . ' ';
            $sql .= 'WHEN ' . $this->leftAttribute . ' BETWEEN ' . $left_id . ' AND ' . $right_id . ' THEN ' . $this->leftAttribute . '+' . ((($right_idp - $right_id - $level + $levelp) / 2) * 2 + $level - $levelp - 1) . ' ELSE ' . $this->leftAttribute . ' END ';
            $sql .= 'WHERE ' . $this->leftAttribute . ' BETWEEN ' . ($left_idp + 1) . ' AND ' . ($right_idp - 1);
        } elseif ($left_idp < $left_id) {
            $sql .= $this->depthAttribute . ' = CASE WHEN ' . $this->leftAttribute . ' BETWEEN ' . $left_id . ' AND ' . $right_id . ' THEN ' . $this->depthAttribute . sprintf(
                    '%+d',
                    -($level - 1) + $levelp
                ) . ' ELSE ' . $this->depthAttribute . ' END, ';
            $sql .= $this->leftAttribute . ' = CASE WHEN ' . $this->leftAttribute . ' BETWEEN ' . $right_idp . ' AND ' . ($left_id - 1) . ' THEN ' . $this->leftAttribute . '+' . ($right_id - $left_id + 1) . ' ';
            $sql .= 'WHEN ' . $this->leftAttribute . ' BETWEEN ' . $left_id . ' AND ' . $right_id . ' THEN ' . $this->leftAttribute . '-' . ($left_id - $right_idp) . ' ELSE ' . $this->leftAttribute . ' END, ';
            $sql .= $this->rightAttribute . ' = CASE WHEN ' . $this->rightAttribute . ' BETWEEN ' . $right_idp . ' AND ' . $left_id . ' THEN ' . $this->rightAttribute . '+' . ($right_id - $left_id + 1) . ' ';
            $sql .= 'WHEN ' . $this->rightAttribute . ' BETWEEN ' . $left_id . ' AND ' . $right_id . ' THEN ' . $this->rightAttribute . '-' . ($left_id - $right_idp) . ' ELSE ' . $this->rightAttribute . ' END ';
            $sql .= 'WHERE (' . $this->leftAttribute . ' BETWEEN ' . $left_idp . ' AND ' . $right_id . ' ';
            $sql .= 'OR ' . $this->rightAttribute . ' BETWEEN ' . $left_idp . ' AND ' . $right_id . ')';
        } else {
            $sql .= $this->depthAttribute . ' = CASE WHEN ' . $this->leftAttribute . ' BETWEEN ' . $left_id . ' AND ' . $right_id . ' THEN ' . $this->depthAttribute . sprintf(
                    '%+d',
                    -($level - 1) + $levelp
                ) . ' ELSE ' . $this->depthAttribute . ' END, ';
            $sql .= $this->leftAttribute . ' = CASE WHEN ' . $this->leftAttribute . ' BETWEEN ' . $right_id . ' AND ' . $right_idp . ' THEN ' . $this->leftAttribute . '-' . ($right_id - $left_id + 1) . ' ';
            $sql .= 'WHEN ' . $this->leftAttribute . ' BETWEEN ' . $left_id . ' AND ' . $right_id . ' THEN ' . $this->leftAttribute . '+' . ($right_idp - 1 - $right_id) . ' ELSE ' . $this->leftAttribute . ' END, ';
            $sql .= $this->rightAttribute . ' = CASE WHEN ' . $this->rightAttribute . ' BETWEEN ' . ($right_id + 1) . ' AND ' . ($right_idp - 1) . ' THEN ' . $this->rightAttribute . '-' . ($right_id - $left_id + 1) . ' ';
            $sql .= 'WHEN ' . $this->rightAttribute . ' BETWEEN ' . $left_id . ' AND ' . $right_id . ' THEN ' . $this->rightAttribute . '+' . ($right_idp - 1 - $right_id) . ' ELSE ' . $this->rightAttribute . ' END ';
            $sql .= 'WHERE (' . $this->leftAttribute . ' BETWEEN ' . $left_id . ' AND ' . $right_idp . ' ';
            $sql .= 'OR ' . $this->rightAttribute . ' BETWEEN ' . $left_id . ' AND ' . $right_idp . ')';
        }

        $sql .= 'AND companyId = ' . $node_parent_info['companyId'];
        $this->getDb()->createCommand($sql)->execute();
    }

    public function setParent($parentId, $id)
    {
        if ($parentId) {
            $this->movePermanentNode($id, $parentId);
            $sql = 'UPDATE ' . self::tableName() . ' SET ';
            $sql .= '`parent_id`=' . $parentId . ' ';
            if ($this->globalParentNode) {
                $sql .= ', `' . $this->treeAttribute . '`=' . $this->globalParentNode . ' ';
            }
            $sql .= 'WHERE `id`=' . $id;
            $command = $this->getDb()->createCommand($sql);
                $command->execute();
            //var_export($command->query());
        }
    }

    private function setAsRoot($id)
    {
        $idInfo = $this->getNodeInfo($id);
        if ($idInfo[$this->leftAttribute] == 0) {
            $this->{$this->leftAttribute} = $this->getNodeMaxRight() + 1;
            $this->{$this->rightAttribute} = $this->{$this->leftAttribute} + 1;
            $idInfo[$this->depthAttribute] = 0;
            $parentId = null;
        }
        $this->setDataToNode(
            $id,
            $idInfo[$this->leftAttribute],
            $idInfo[$this->rightAttribute],
            $idInfo[$this->depthAttribute],
            $parentId
        );
    }

    public function fixTree()
    {
        foreach ($this->nodeIdList as $item) {
            if ($this->globalParentNode) {
                $idInfo = $this->getNodeInfo($item);
                if ($this->parentCriteria !== 'parent_id') {
                    if ($item != $idInfo[$this->parentCriteria] && $idInfo[$this->parentCriteria] != null) {
                        $this->setParent($idInfo[$this->parentCriteria], $item);
                        continue;
                    }
                }
                if ($item != $this->globalParentNode) {
                    $this->setParent($this->globalParentNode, $item);
                }
                continue;
            }
            // надо было бы SET AS ROOT но они и так root;
        }
    }

    private function setDataToNode($id, $left, $right, $dept, $parentId)
    {
        $this->getDb()->createCommand()->update(
            self::tableName(),
            [
                $this->leftAttribute => $left,
                $this->rightAttribute => $right,
                $this->depthAttribute => $dept,
                'parent_id' => $parentId,
            ],
            ['=', 'id', $id]
        )->execute();
    }

    private function getNodeInfo(?int $nodeId)
    {
        $parentAttribute = '';
        if ($this->parentCriteria !== 'parent_id') {
            $parentAttribute = ', ' . $this->parentCriteria;
        }

        return $this->getDb()->createCommand(
            'SELECT ' . $this->leftAttribute .
            ', ' . $this->rightAttribute .
            ', ' . $this->depthAttribute . ', id, parent_id, companyId ' . $parentAttribute . ' FROM '
            . self::tableName() . ' WHERE id=:id'
        )
            ->bindValue(':id', $nodeId)
            ->queryOne();
    }

}