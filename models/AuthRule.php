<?php

namespace futuretek\rbac\models;

use futuretek\yii\shared\DbModel;
use Yii;

/**
 * This is the model class for table "auth_rule".
 *
 * @property string $name
 * @property string $data
 * @property integer $created_at
 * @property integer $updated_at
 *
 * @property AuthItem[] $authItems
 */
class AuthRule extends DbModel
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'auth_rule';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['name'], 'required'],
            [['data'], 'string'],
            [['created_at', 'updated_at'], 'integer'],
            [['name'], 'string', 'max' => 64],
        ];
    }

    /**
     * @inheritdoc
     */
    public function scenarios()
    {
        return [
            'default' => [
                'name', 'data',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'name' => Yii::t('fts-yii2-rbac', 'Name'),
            'data' => Yii::t('fts-yii2-rbac', 'Data'),
            'created_at' => Yii::t('fts-yii2-rbac', 'Created At'),
            'updated_at' => Yii::t('fts-yii2-rbac', 'Updated At'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeHints()
    {
        return [
            'name' => Yii::t('fts-yii2-rbac', ''),
            'data' => Yii::t('fts-yii2-rbac', ''),
            'created_at' => Yii::t('fts-yii2-rbac', ''),
            'updated_at' => Yii::t('fts-yii2-rbac', ''),
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getAuthItems()
    {
        return $this->hasMany(AuthItem::className(), ['rule_name' => 'name'])->inverseOf('ruleName');
    }
}
