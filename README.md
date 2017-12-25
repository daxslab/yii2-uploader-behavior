Yii2 Uploader Behavior for Active Record
========================================
UploaderBehavior automates file uploading and configures the way that filename should be renamed and stored in database
as attribute value.

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist daxslab/yii2-uploader-behavior "*"
```

or add

```
"daxslab/yii2-uploader-behavior": "*"
```

to the require section of your `composer.json` file.


Usage
-----

Once the extension is installed, simply use it in your models by  :

```php

use daxslab/behaviors/UploaderBehavior;

public function behaviors() {
    return [
        UploaderBehavior::className()
    ];
}

```

This is the simplest way of usage. It will look for all attributes with a `file` or `image` validation rule
and upload the file specified with an `ActiveField::fileInput()` field.

```php
public function rules()
{
    return [
        [['name'], 'required'],
        [['name'], 'string', 'max' => 256],
        [['image'], 'image', 'skipOnEmpty' => true],
    ];
}

```

Or you can specify which attributes to control using the `attributes` option as in  :

```php

use daxslab/behaviors/UploaderBehavior;

public function behaviors() {
    return [
        [
            'class' => UploaderBehavior::className(),
            'attributes' => ['avatar'] // or you can use the string format as in 'attributes' => 'avatar'
        ]
    ];
}

```

By default the filename of the uploaded file is randomized, but if you want to configure it you can setup the `renamer`
option:

```php

use daxslab/behaviors/UploaderBehavior;

public function behaviors() {
    return [
        [
            'class' => UploaderBehavior::className(),
            'attributes' => ['avatar'] // or you can use the string format as in 'attributes' => 'avatar'
            'renamer' => UploaderBehavior::RENAME_MD5 //will encode the filename with md5()
        ]
    ];
}

```

also you can specify a custom function to rename the filename:

```php

use daxslab/behaviors/UploaderBehavior;

public function behaviors() {
    return [
        [
            'class' => UploaderBehavior::className(),
            'attributes' => ['avatar'] // or you can use the string format as in 'attributes' => 'avatar'
            'renamer' => function($name, $owner){
                return strtoupper($name); //will turn the filename into uppercase
            }
        ]
    ];
}

```
