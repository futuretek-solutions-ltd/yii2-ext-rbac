<?php

namespace futuretek\rbac\models;

use futuretek\yii\shared\DbModel;
use Yii;

/**
 * This is the model class for table "auth_item_child".
 *
 * @property string $parent
 * @property string $child
 *
 * @property AuthItem $parentItem
 * @property AuthItem $childItem
 */
class AuthItemChild extends DbModel
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'auth_item_child';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['parent', 'child'], 'required'],
            [['parent', 'child'], 'string', 'max' => 64],
            [['parent'], 'exist', 'skipOnError' => true, 'targetClass' => AuthItem::className(), 'targetAttribute' => ['parent' => 'name']],
            [['child'], 'exist', 'skipOnError' => true, 'targetClass' => AuthItem::className(), 'targetAttribute' => ['child' => 'name']],
        ];
    }

    /**
     * @inheritdoc
     */
    public function scenarios()
    {
        return [
            'default' => [
                'parent', 'child',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'parent' => Yii::t('fts-yii2-rbac', 'Parent'),
            'child' => Yii::t('fts-yii2-rbac', 'Child'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeHints()
    {
        return [
            'parent' => Yii::t('fts-yii2-rbac', ''),
            'child' => Yii::t('fts-yii2-rbac', ''),
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getParentItem()
    {
        return $this->hasOne(AuthItem::className(), ['name' => 'parent'])->inverseOf('authItemParents');
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getChildItem()
    {
        return $this->hasOne(AuthItem::className(), ['name' => 'child'])->inverseOf('authItemChildren');
    }
}
