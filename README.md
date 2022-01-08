# RBAC

## Requirements

* For RBAC to function correctly Yii cache component must be configured.

## Configuration

Place config into @app/rbac/config.php

```
<?php
return [
    'roles' => [
        ['name' => 'admin', 'description' => 'Unrestricted access including developer tools.', 'system' => true, 'data' => null],
        .
        .
    ],
    'permissions' => [
        'operator' => [
            'CallCenter' => 'all',
            'Order' => ['Index', 'View'],
            .
            .
        ],
        .
        .
    ],
    'specialPermissions' => [
        [
            'category' => 'main',
            'action' => 'login',
            'name' => 'MainLogin',
            'description' => 'Allow to login the user',
        ],
        .
        .
    ],
];
```

### Console actions

* yii rbac/init - Initialize RBAC database, builds permissions and default roles. 
* yii rbac/export - Export permissions to file.
* yii rbac/import - Import permissions from file based on application language.

## Usage

### Permissions Init

**ATTENTION** This command should be used only in development process. 

Permission names are created from controller and action name.

Init command will create permissions and roles.
Also admin user is created and granted all permissions.

When in developer mode, this command enables you to clear original permissions. 
In other environments only permission update is possible.

### Permissions Export

Permissions should be exported and committed to versioning system when releasing the application. 

Export is done using the command:
```
yii rbac/export
```

These files are created in `@app/rbac/` during export:
* `auth-assignment`
* `auth-item`
* `auth-item-child`
* `auth-rule`

### Permissions Import

Permission import should be executed when new release is deployed on target instance. 
Import will restore exported permissions from files to database.

Import is done using command:
```
yii rbac/import
```

Import expect that files noted above are present in `@app/rbac/`

**Note**: file `auth-item` should be renamed to current language to import to work properly - see next chapter. 

### Permission translation

Authentication items (permission and role names) contained in file `auth-item` can/should be translated.

When importing permissions, the script is looking for file corresponding to selected 
language (`Yii::$app->language`). File format is:
```
auth-item.<iso-code>
```
where `<iso-code>` is two digit language ISO code. For example `auth-item.en`. 

If application language is set using locale (eg. en-US), only first part (language code) is used.

### Usage in application

For operations with permissions (create, update, delete) use `AuthItem` model (instead of Yii:$app->authManager).

Permission checking method has not changed - `Yii::$app->user->can()`.
