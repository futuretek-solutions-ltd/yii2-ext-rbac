# RBAC

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

## Changelog

### 1.0.0
* Initial version 
