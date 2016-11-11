<?php

namespace futuretek\rbac\models;

use futuretek\yii\shared\DbModel;
use Yii;

/**
 * This is the model class for table "auth_assignment".
 *
 * @property string $item_name
 * @property integer $user_id
 * @property integer $created_at
 *
 * @property AuthItem $itemName
 */
class AuthAssignment extends DbModel
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'auth_assignment';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['item_name', 'user_id'], 'required'],
            [['user_id', 'created_at'], 'integer'],
            [['item_name'], 'string', 'max' => 64],
            [['item_name'], 'exist', 'skipOnError' => true, 'targetClass' => AuthItem::className(), 'targetAttribute' => ['item_name' => 'name']],
        ];
    }

    /**
     * @inheritdoc
     */
    public function scenarios()
    {
        return [
            'default' => [
                'item_name', 'user_id',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'item_name' => Yii::t('fts-yii2-rbac', 'Item Name'),
            'user_id' => Yii::t('fts-yii2-rbac', 'User'),
            'created_at' => Yii::t('fts-yii2-rbac', 'Created At'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeHints()
    {
        return [
            'item_name' => Yii::t('fts-yii2-rbac', ''),
            'user_id' => Yii::t('fts-yii2-rbac', ''),
            'created_at' => Yii::t('fts-yii2-rbac', ''),
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getItemName()
    {
        return $this->hasOne(AuthItem::className(), ['name' => 'item_name'])->inverseOf('authAssignments');
    }
}
