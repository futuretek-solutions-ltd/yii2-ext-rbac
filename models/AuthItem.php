<?php

namespace futuretek\rbac\models;

use futuretek\yii\shared\DbModel;
use futuretek\yii\shared\FtsException;
use futuretek\yii\shared\ModelSaveException;
use Yii;
use yii\rbac\DbManager;
use yii\rbac\Role;

/**
 * This is the model class for table "auth_item".
 *
 * @property string $name
 * @property integer $type
 * @property string $description
 * @property string $rule_name
 * @property string $data
 * @property bool $system
 * @property string $category
 * @property integer $created_at
 * @property integer $updated_at
 *
 * @property AuthAssignment[] $authAssignments
 * @property AuthRule $ruleName
 * @property AuthItemChild[] $authItemParents
 * @property AuthItemChild[] $authItemChildren
 * @property AuthItem[] $children
 * @property AuthItem[] $parents
 * @property AuthItem[] $roles
 */
class AuthItem extends DbModel
{
    const TYPE_ROLE = 1;
    const TYPE_PERMISSION = 2;

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'auth_item';
    }

    /**
     * @inheritDoc
     */
    public static function primaryKey()
    {
        return ['name'];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['name', 'type'], 'required'],
            [['type', 'created_at', 'updated_at'], 'integer'],
            [['system'], 'boolean'],
            [['description', 'data'], 'string'],
            [['name', 'rule_name'], 'string', 'max' => 64],
            [['category'], 'string', 'max' => 32],
            [['name'], 'unique'],
            [['rule_name'], 'exist', 'skipOnError' => true, 'targetClass' => AuthRule::className(), 'targetAttribute' => ['rule_name' => 'name']],
        ];
    }

    /**
     * @inheritdoc
     */
    public function scenarios()
    {
        return [
            'default' => [
                'name', 'type', 'description', 'rule_name', 'data', 'system', 'category',
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
            'type' => Yii::t('fts-yii2-rbac', 'Type'),
            'description' => Yii::t('fts-yii2-rbac', 'Description'),
            'rule_name' => Yii::t('fts-yii2-rbac', 'Rule Name'),
            'data' => Yii::t('fts-yii2-rbac', 'Data'),
            'system' => Yii::t('fts-yii2-rbac', 'System'),
            'category' => Yii::t('fts-yii2-rbac', 'Category'),
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
            'type' => Yii::t('fts-yii2-rbac', ''),
            'description' => Yii::t('fts-yii2-rbac', ''),
            'rule_name' => Yii::t('fts-yii2-rbac', ''),
            'data' => Yii::t('fts-yii2-rbac', ''),
            'system' => Yii::t('fts-yii2-rbac', ''),
            'category' => Yii::t('fts-yii2-rbac', ''),
            'created_at' => Yii::t('fts-yii2-rbac', ''),
            'updated_at' => Yii::t('fts-yii2-rbac', ''),
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getAuthAssignments()
    {
        return $this->hasMany(AuthAssignment::className(), ['item_name' => 'name'])->inverseOf('itemName');
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getRuleName()
    {
        return $this->hasOne(AuthRule::className(), ['name' => 'rule_name'])->inverseOf('authItems');
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getAuthItemParents()
    {
        return $this->hasMany(AuthItemChild::className(), ['parent' => 'name'])->inverseOf('parentItem');
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getAuthItemChildren()
    {
        return $this->hasMany(AuthItemChild::className(), ['child' => 'name'])->inverseOf('childItem');
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getChildren()
    {
        return $this->hasMany(AuthItem::className(), ['name' => 'child'])->viaTable('auth_item_child', ['parent' => 'name']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getParents()
    {
        return $this->hasMany(AuthItem::className(), ['name' => 'parent'])->viaTable('auth_item_child', ['child' => 'name']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getRoles()
    {
        return self::find()->where(['type' => Role::TYPE_ROLE]);
    }

    /**
     * Check if role has permission
     *
     * @param string $permissionName Permission name
     * @return bool
     * @throws FtsException
     */
    public function hasPermission($permissionName)
    {
        if ($this->type === self::TYPE_PERMISSION) {
            throw new FtsException(Yii::t('fts-yii2-rbac', 'Cannot check permission on permission object.'));
        }

        return AuthItemChild::find()->where(['parent' => $this->name, 'child' => $permissionName])->exists();
    }

    /**
     * Add permission to current role
     *
     * @param string $permissionName Permission name
     * @throws FtsException
     * @throws \futuretek\yii\shared\ModelSaveException
     */
    public function addPermission($permissionName)
    {
        if ($this->type === self::TYPE_PERMISSION) {
            throw new FtsException(Yii::t('fts-yii2-rbac', 'Cannot add permission to permission.'));
        }
        if (!$this->hasPermission($permissionName)) {
            $obj = new AuthItemChild();
            $obj->parent = $this->name;
            $obj->child = $permissionName;
            if (!$obj->save()) {
                throw new ModelSaveException($obj);
            }
        }
    }

    /**
     * Check if permission is assigned to role
     *
     * @param string $roleName Role name
     * @return bool
     * @throws FtsException
     */
    public function hasRole($roleName)
    {
        if ($this->type === self::TYPE_ROLE) {
            throw new FtsException(Yii::t('fts-yii2-rbac', 'Cannot check role on role object.'));
        }

        return AuthItemChild::find()->where(['child' => $this->name, 'parent' => $roleName])->exists();
    }

    /**
     * Add current permission to specified role
     *
     * @param string $roleName Permission name
     * @throws FtsException
     * @throws \futuretek\yii\shared\ModelSaveException
     */
    public function addPermissionToRole($roleName)
    {
        if ($this->type === self::TYPE_ROLE) {
            throw new FtsException(Yii::t('fts-yii2-rbac', 'Cannot add role to role.'));
        }

        if (!$this->hasRole($roleName)) {
            $obj = new AuthItemChild();
            $obj->parent = $roleName;
            $obj->child = $this->name;
            if (!$obj->save()) {
                throw new ModelSaveException($obj);
            }
        }
    }

    public static function clearCache(): void
    {
        $authManager = Yii::$app->getAuthManager();
        if ($authManager instanceof DbManager) {
            $authManager->invalidateCache();
        }
    }
}
