<?php

use futuretek\migrations\FtsMigration;

/**
 * Class m161111_173101_init
 *
 * @package futuretek\rbac
 * @author  Lukas Cerny <lukas.cerny@futuretek.cz>
 * @license http://www.futuretek.cz/license FTSLv1
 * @link    http://www.futuretek.cz
 */
class m161111_173101_init extends FtsMigration
{
    public function safeUp()
    {
        Yii::$app->db->createCommand()->checkIntegrity(false)->execute();

        //auth_rule
        if (!Yii::$app->db->schema->getTableSchema('auth_rule')) {
            $this->createTable('auth_rule', [
                'name' => $this->string(64)->notNull(),
                'data' => $this->text(),
                'created_at' => $this->dateTime(),
                'updated_at' => $this->dateTime(),
            ]);
            $this->addPrimaryKey('auth_rule_PRIMARY', 'auth_rule', 'name');
        }

        //auth_item
        if (!Yii::$app->db->schema->getTableSchema('auth_item')) {
            $this->createTable('auth_item', [
                'name' => $this->string(64)->notNull(),
                'type' => $this->integer(1)->notNull(),
                'description' => $this->text(),
                'rule_name' => $this->string(64),
                'data' => $this->text(),
                'system' => $this->boolean()->notNull()->defaultValue(false),
                'category' => $this->string(32),
                'created_at' => $this->dateTime(),
                'updated_at' => $this->dateTime(),
            ]);
            $this->addPrimaryKey('auth_item_PRIMARY', 'auth_item', 'name');
            $this->createIndex('auth_item_type', 'auth_item', 'type');
            $this->createIndex('fk_auth_item_auth_rule_idx', 'auth_item', 'rule_name');
            $this->addForeignKey('fk_auth_item_auth_rule_idx', 'auth_item', 'rule_name', 'auth_rule', 'name', 'SET NULL', 'CASCADE');
        }

        //auth_item_child
        if (!Yii::$app->db->schema->getTableSchema('auth_item_child')) {
            $this->createTable('auth_item_child', [
                'parent' => $this->string(64)->notNull(),
                'child' => $this->string(64)->notNull(),
            ]);
            $this->addPrimaryKey('auth_item_child_PRIMARY', 'auth_item_child', ['parent', 'child']);
            $this->createIndex('fk_auth_item_child_auth_rule1_idx', 'auth_item_child', 'parent');
            $this->addForeignKey('fk_auth_item_child_auth_rule1', 'auth_item_child', 'parent', 'auth_item', 'name', 'CASCADE', 'CASCADE');
            $this->createIndex('fk_auth_item_child_auth_rule2_idx', 'auth_item_child', 'child');
            $this->addForeignKey('fk_auth_item_child_auth_rule2', 'auth_item_child', 'child', 'auth_item', 'name', 'CASCADE', 'CASCADE');
        }

        //auth_assignment
        if (!Yii::$app->db->schema->getTableSchema('auth_assignment')) {
            $this->createTable('auth_assignment', [
                'item_name' => $this->string(64)->notNull(),
                'user_id' => $this->integer(11)->notNull(),
                'created_at' => $this->dateTime(),
            ]);
            $this->addPrimaryKey('auth_assignment_PRIMARY', 'auth_assignment', ['item_name', 'user_id']);
            $this->createIndex('fk_auth_item_assignment_auth_item1_idx', 'auth_assignment', 'item_name');
            $this->addForeignKey('fk_auth_item_assignment_auth_item1', 'auth_assignment', 'item_name', 'auth_item', 'name', 'CASCADE', 'CASCADE');
        }

        Yii::$app->db->createCommand()->checkIntegrity(true)->execute();
    }

    public function safeDown()
    {
        Yii::$app->db->createCommand()->checkIntegrity(false)->execute();

        $this->dropTable('auth_assignment');
        $this->dropTable('auth_item');
        $this->dropTable('auth_item_child');
        $this->dropTable('auth_rule');

        Yii::$app->db->createCommand()->checkIntegrity(true)->execute();
    }
}
