<?php

/**
 * @link http://www.daxslab.com/
 * @copyright Copyright (c) 2016 Daxslab
 * @license http://www.yiiframework.com/license/
 */

namespace daxslab\behaviors;

use Closure;
use Yii;
use yii\base\Behavior;
use yii\base\InvalidConfigException;
use yii\base\Model;
use yii\db\ActiveRecord;
use yii\helpers\FileHelper;
use yii\helpers\Inflector;
use yii\web\UploadedFile;

/**
 *  UploaderBehavior automates file uploading for every attribute tagged with `file` or `image` validation rule. 
 *  It also configures the way that filename should be renamed and stored in database as attribute value. For a basic usage:
 *
 * ```php
 * use daxslab\behaviors\UploaderBehavior;
 *
 * public function behaviors()
 * {
 *     return [
 *         UploaderBehavior::className()
 *     ];
 * }
 * ```
 *
 * Here it looks for all attributes with a `file` or `image` validation rule and uploads the files received with a `fileInput` field.
 * A more complex usage is:
 *
 * ```php
 * use daxslab\behaviors\UploaderBehavior;
 *
 * public function behaviors()
 * {
 *     return [
 *          [
 *              'class' => UploaderBehavior::className(),
 *              'attributes' => 'avatar',
 *              'renamer' => function($name, $owner){
 *                  return strtoupper($name);
 *              }
 *          ]
 *     ];
 * }
 * ```
 *
 * will uploads the files and converts its filenames to uppercase;
 *
 * @author Gabriel A. López López <glpz@daxslab.com>
 * @since 2.0.14
 */
class UploaderBehavior extends Behavior {

    /**
     * Renamer mode that defines that final filename will be kept as original.
     */
    const RENAME_NO_RENAME = 0;

    /**
     * Renamer mode that defines that final filename will be encoded with md5().
     */
    const RENAME_MD5 = 1;

    /**
     * Renamer mode that defines that final filename will be encoded with sha1().
     */
    const RENAME_SHA1 = 2;

    /**
     * Renamer mode that defines that final filename will be processed with yii\helpers\Inflector::slug() method.
     * @see Inflector::slug()
     */
    const RENAME_SLUG = 3;

    /**
     * Renamer mode that defines that final filename will be random-generated.
     */
    const RENAME_RANDOM = 4;

    /**
     * @var string the directory to store uploaded files. You may use path alias here.
     * If not set, it will use the "uploads" subdirectory under the application public directory.
     */
    public $uploadPath = '@app/web/uploads';

    /**
     * @var integer|Closure an anonymous function or an integer that is used to determine how the file is going to be renamed.
     *
     * If this is an anonymous function, it will be called for each uploaded file and the return value will be used to
     * rename it and store its name on database.
     *
     * The signature of this function should be: `function ($name, $owner)`.
     * Where `$name` is the original filename and `$owner` refers to the model.
     *
     * You may also set this property to one of the integer constants defined as UploaderBehavior::RENAME_*.
     */
    public $renamer = self::RENAME_RANDOM;

    /**
     * @var boolean if the file should be deleted when the owner model is deleted
     */
    public $autoDelete = true;

    /**
     * @var boolean if the old file should be deleted when a new file is uploaded
     */
    public $deleteOldFileOnUpdate = true;

    /**
     * @var string|array the attribute or attributes that represents uploaded files.
     */
    public $attributes = null;

    /**
     * @var array holds old filenames to restore them if no file is uploaded.
     */
    private $_oldAttributes = [];

    /**
     * @inheritdoc
     *
     * Checks for a valid configuration
     */
    public function init() {
        if (!(in_array($this->renamer, [
                self::RENAME_NO_RENAME,
                self::RENAME_MD5,
                self::RENAME_SHA1,
                self::RENAME_SLUG,
                self::RENAME_RANDOM
            ]) || is_callable($this->renamer))) {
            throw new InvalidConfigException('The "renamer" option must be a callable or a valid UploaderBehavior::RENAME_* constant');
        }
    }

    /**
     * @inheritdoc
     *
     * Setups the events and checks the attributes configuration. If no attribute has been set it
     * loads all attributes with `file` validator.
     */
    public function events() {
        if ($this->attributes != null) {
            if (is_array($this->attributes)) {
                // ok
            } else if (is_string($this->attributes)) {
                $attribute = $this->attributes;
                $this->attributes = [$attribute];
            } else {
                throw new InvalidConfigException('The "attributes" option must be of type string or array');
            }
        } else {
            //try to detect fields with file validation rule
            $this->attributes = [];
            $rules = $this->owner->rules();
            foreach ($rules as $rule) {
                if ($rule[1] == 'file' OR $rule[1] == 'image') {
                    if (is_array($rule[0])) {
                        $this->attributes = array_merge($this->attributes, $rule[0]);
                    } else {
                        $this->attributes[] = $rule[0];
                    }
                }
            }
        }

        return [
            ActiveRecord::EVENT_AFTER_FIND => 'doAfterFind',
            Model::EVENT_BEFORE_VALIDATE => 'doBeforeValidate',
            ActiveRecord::EVENT_AFTER_INSERT => 'doAfterInsert',
            ActiveRecord::EVENT_AFTER_UPDATE => 'doAfterUpdate',
            ActiveRecord::EVENT_AFTER_DELETE => 'doAfterDelete',
        ];
    }

    public function doAfterFind() {
        foreach ($this->attributes as $attr) {
            $this->_oldAttributes[$attr] = $this->owner->$attr;
        }
    }

    public function doBeforeValidate() {
        if (is_array($this->attributes)) {
            foreach ($this->attributes as $attr) {
                $this->proccessAttribute($attr);
            }
        } else {
            $this->proccessAttribute($this->attributes);
        }
    }

    public function doAfterInsert() {
        $this->upload();
    }

    public function doAfterUpdate() {
        $this->upload();
    }

    public function doAfterDelete() {
        if ($this->autoDelete) {
            foreach ($this->attributes as $attr) {
                $filename = Yii::getAlias("{$this->uploadPath}/{$this->owner->$attr}");
                if (file_exists($filename) AND is_file($filename)) {
                    unlink($filename);
                }
            }
        }
    }

    protected function proccessAttribute($attribute) {
        $this->owner->$attribute = UploadedFile::getInstance($this->owner, $attribute);
        $upload = $this->owner->$attribute;
        if ($upload instanceof UploadedFile) {
            $upload->name = $this->renameFile($upload->baseName) . "." . $upload->extension;
        } else {
            if (isset($this->_oldAttributes[$attribute])) {
                $this->owner->$attribute = $this->_oldAttributes[$attribute];
            }
        }
    }

    protected function renameFile($name) {
        $newName = $name;
        if (is_callable($this->renamer)) {
            $newName = call_user_func($this->renamer, $name, $this->owner);
        } else {
            switch ($this->renamer) {
                case self::RENAME_MD5:
                    $newName = md5($name);
                    break;
                case self::RENAME_SHA1:
                    $newName = sha1($name);
                    break;
                case self::RENAME_SLUG:
                    $newName = Inflector::slug($name);
                    break;
                case self::RENAME_RANDOM:
                    $newName = Yii::$app->security->generateRandomString();
                    break;
            }
        }
        return $newName;
    }

    protected function upload() {
        FileHelper::createDirectory(Yii::getAlias($this->uploadPath));
        if (is_array($this->attributes)) {
            foreach ($this->attributes as $attr) {
                $this->uploadFile($attr);
            }
        } else {
            $this->uploadFile($this->attributes);
        }
    }

    protected function uploadFile($attribute) {
        $upload = $this->owner->$attribute;
        if ($upload instanceof UploadedFile) {
            if ($upload->saveAs(Yii::getAlias("{$this->uploadPath}/{$upload->name}")) && $this->deleteOldFileOnUpdate) {
                if ($this->deleteOldFileOnUpdate && !$this->owner->isNewRecord && isset($this->_oldAttributes[$attribute])) {
                    $filenameToDelete = Yii::getAlias("{$this->uploadPath}/{$this->_oldAttributes[$attribute]}");
                    if (file_exists($filenameToDelete) AND is_file($filenameToDelete)) {
                        unlink($filenameToDelete);
                    }
                }
            }
        } else {
            return false;
        }
    }

}
