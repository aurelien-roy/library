<?php namespace October\Rain\Database;

use Hash;
use Input;
use Closure;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Facades\Validator;
use October\Rain\Database\Relations\BelongsTo;
use October\Rain\Database\Relations\BelongsToMany;
use October\Rain\Database\Relations\HasMany;
use October\Rain\Database\Relations\HasOne;
use October\Rain\Database\Relations\MorphMany;
use October\Rain\Database\Relations\MorphOne;
use October\Rain\Database\Relations\AttachMany;
use October\Rain\Database\Relations\AttachOne;
use October\Rain\Database\Relations\hasManyThrough;
use October\Rain\Database\ModelException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Active Record base class.
 *
 * Extends Eloquent with added extendability and deferred bindings.
 *
 * @package october\database
 * @author Alexey Bobkov, Samuel Georges
 */
class Model extends EloquentModel
{
    use \October\Rain\Support\Traits\Emitter;
    use \October\Rain\Extension\ExtendableTrait;

    /**
     * @var array Behaviors implemented by this model.
     */
    public $implement;

    /**
     * @var array Make the model's attributes public so behaviors can modify them.
     */
    public $attributes = [];

    /**
     * @var array The rules to be applied to the data.
     */
    public $rules = [];

    /**
     * @var array The array of custom error messages.
     */
    public $customMessages = [];

    /**
     * @var \Illuminate\Support\MessageBag The message bag instance containing validation error messages
     */
    public $validationErrors;

    /**
     * @var bool Makes the validation procedure throw an {@link October\Rain\Database\ModelException} instead of returning
     * false when validation fails.
     */
    public $throwOnValidation = true;

    /**
     * @var array List of attribute names which should be hashed using the Bcrypt hashing algorithm.
     */
    protected $hashable = [];

    /**
     * @var array List of attribute names which should not be saved to the database.
     */
    protected $purgeable = [];

    /**
     * @var array List of attribute names which are json encoded and decoded from the database.
     */
    protected $jsonable = [];

    /**
     * @var array List of original attribute values before they were hashed.
     */
    private $originalHashableValues = [];

    /**
     * @var array List of original attribute values before they were purged.
     */
    private $originalPurgeableValues = [];

    /**
     * Cleaner declaration of relationships.
     * Uses a similiar approach to the relation methods used by Eloquent, but as separate properties
     * that make the class file less cluttered.
     *
     * It should be declared with keys as the relation name, and value being a mixed array.
     * The relation type $morphTo does not include a classname as the first value.
     *
     * Example:
     * class Order extends Model
     * {
     *     protected $hasMany = [
     *         'items' => 'Item'
     *     ];
     * }
     * @var array
     */
    public $hasMany = [];

    /**
     * protected $hasOne = [
     *     'owner' => ['User', 'foreignKey'=>'user_id']
     * ];
     */
    public $hasOne = [];

    /**
     * protected $attachOne = [
     *     'pictures' => ['Picture', 'name'=> 'imageable']
     * ];
     */
    public $belongsTo = [];

    /**
     * protected $belongsToMany = [
     *     'groups' => ['Group', 'table'=> 'join_groups_users']
     * ];
     */
    public $belongsToMany = [];

    /**
     * protected $morphTo = [
     *     'pictures' => []
     * ];
     */
    public $morphTo = [];

    /**
     * protected $morphOne = [
     *     'log' => ['History', 'name' => 'user']
     * ];
     */
    public $morphOne = [];

    /**
     * protected $morphMany = [
     *     'log' => ['History', 'name' => 'user']
     * ];
     */
    public $morphMany = [];

    /**
     * protected $attachOne = [
     *     'picture' => ['October\Rain\Database\Attach\File', 'public' => false]
     * ];
     */
    public $attachOne = [];

    /**
     * protected $attachMany = [
     *     'pictures' => ['October\Rain\Database\Attach\File', 'name'=> 'imageable']
     * ];
     */
    public $attachMany = [];

    /**
     * protected $attachMany = [
     *     'pictures' => ['Picture', 'name'=> 'imageable']
     * ];
     */
    public $hasManyThrough = [];

    /**
     * @var array Excepted relationship types, used to cycle and verify relationships.
     */
    protected static $relationTypes = ['hasOne', 'hasMany', 'belongsTo', 'belongsToMany', 'morphTo', 'morphOne', 'morphMany', 'attachOne', 'attachMany', 'hasManyThrough'];

    /**
     * @var string A unique session key used for deferred binding.
     */
    public $sessionKey;

    /**
     * Constructor
     */
    public function __construct(array $attributes = array())
    {
        $this->extendableConstruct();
        parent::__construct($attributes);

        $this->validationErrors = new MessageBag;
    }

    /**
     * Create a new model and return the instance.
     * @param array $attributes
     * @return \Illuminate\Database\Eloquent\Model|static
     */
    public static function make($attributes = [])
    {
        return new static($attributes);
    }

    /**
     * Save a new model and return the instance.
     * @param array $attributes
     * @param string $sessionKey
     * @return \Illuminate\Database\Eloquent\Model|static
     */
    public static function create(array $attributes, $sessionKey = null)
    {
        $model = new static($attributes);
        $model->save(null, $sessionKey);
        return $model;
    }

    /**
     * The "booting" method of the model.
     * Overrided to attach before/after method hooks into the model events.
     * @see \Illuminate\Database\Eloquent\Model::boot()
     * @return void
     */
    public static function boot()
    {
        parent::boot();
        self::bindNicerEvents();
    }

    /**
     * Extend this object properties upon construction.
     */
    public static function extend(Closure $callback)
    {
        self::extendableExtendCallback($callback);
    }

    /**
     * Bind some nicer events to this model, in the format of method overrides.
     */
    private static function bindNicerEvents()
    {
        $self = get_called_class();
        $radicals = ['creat', 'sav', 'validat', 'updat', 'delet', 'restor', 'fetch'];
        $hooks = ['before' => 'ing', 'after' => 'ed'];

        foreach ($radicals as $radical) {
            foreach ($hooks as $hook => $event) {

                $eventMethod = $radical . $event; // saving / saved
                $method = $hook . ucfirst($radical); // beforeSave / afterSave
                if ($radical != 'fetch') $method .= 'e';

                self::$eventMethod(function($model) use ($self, $method) {
                    $model->trigger('model.' . $method);

                    if ($model->methodExists($method))
                        return $model->$method();
                });
            }
        }
    }

    /**
     * Create a new model instance that is existing.
     * @param  array  $attributes
     * @return \Illuminate\Database\Eloquent\Model|static
     */
    public function newFromBuilder($attributes = array())
    {
        $instance = $this->newInstance(array(), true);
        if ($instance->fireModelEvent('fetching') === false)
            return $instance;

        $instance->setRawAttributes((array) $attributes, true);

        $instance->fireModelEvent('fetched', false);

        return $instance;
    }

    /**
     * Create a new native event for handling beforeFetch().
     * @param Closure|string $callback
     * @return void
     */
    public static function fetching($callback)
    {
        static::registerModelEvent('fetching', $callback);
    }

    /**
     * Create a new native event for handling afterFetch().
     * @param Closure|string $callback
     * @return void
     */
    public static function fetched($callback)
    {
        static::registerModelEvent('fetched', $callback);
    }

    /**
     * Create a new native event for handling beforeValidate().
     * @param Closure|string $callback
     * @return void
     */
    public static function validating($callback)
    {
        static::registerModelEvent('validating', $callback);
    }

    /**
     * Create a new native event for handling afterValidate().
     * @param Closure|string $callback
     * @return void
     */
    public static function validated($callback)
    {
        static::registerModelEvent('validated', $callback);
    }

    /**
     * Get the observable event names.
     * @return array
     */
    public function getObservableEvents()
    {
        return array_merge(
            array(
                'creating', 'created', 'updating', 'updated',
                'deleting', 'deleted', 'saving', 'saved',
                'restoring', 'restored', 'fetching', 'fetched'
            ),
            $this->observables
        );
    }

    //
    // Magic
    //

    public function __get($name)
    {
        return $this->extendableGet($name);
    }

    public function __set($name, $value)
    {
        return $this->extendableSet($name, $value);
    }

    public function __call($name, $params = null)
    {
        /*
         * Never call handleRelation() anywhere else as it could
         * break getRelationCaller(), use $this->{$name}() instead
         */
        if ($this->hasRelation($name))
            return $this->handleRelation($name);

        return $this->extendableCall($name, $params);
    }

    /**
     * This a custom piece of logic specifically to satisfy Twig's
     * desire to return a relation object instead of loading the
     * related model.
     *
     * @param  mixed  $offset
     * @return bool
     */
    public function offsetExists($offset)
    {
        if ($result = isset($this->$offset))
            return $result;

        return $this->hasRelation($offset);
    }

    //
    // Relations
    //

    /**
     * Checks if model has a relationship by supplied name.
     * @param string $name Relation name
     * @return bool
     */
    public function hasRelation($name)
    {
        return $this->getRelationDefinition($name) !== null ? true : false;
    }

    /**
     * Returns relationship details from a supplied name.
     * @param string $name Relation name
     * @return array
     */
    public function getRelationDefinition($name)
    {
        if (($type = $this->getRelationType($name)) !== null) {
            return $this->{$type}[$name];
        }
    }

    /**
     * Returns a relationship type based on a supplied name.
     * @param string $name Relation name
     * @return string
     */
    public function getRelationType($name)
    {
        foreach (static::$relationTypes as $type) {
            if (isset($this->{$type}[$name]))
                return $type;
        }
    }

    /**
     * Returns a relation class object
     * @param string $name Relation name
     * @return string
     */
    public function makeRelation($name)
    {
        $relationType = $this->getRelationType($name);
        $relation = $this->getRelationDefinition($name);

        if ($relationType == 'morphTo' || !isset($relation[0]))
            return null;

        $relationClass = $relation[0];
        return new $relationClass();
    }

    /**
     * Looks for the relation and does the correct magic as Eloquent would require
     * inside relation methods. For more information, read the documentation of the mentioned property.
     * @param string $relationName the relation key, camel-case version
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     */
    protected function handleRelation($relationName)
    {
        $relationType = $this->getRelationType($relationName);
        $relation = $this->getRelationDefinition($relationName);

        if (!isset($relation[0]) && $relationType != 'morphTo')
            throw new \InvalidArgumentException("Relation '".$relationName."' on model '".get_called_class().' should have at least a classname.');

        if (isset($relation[0]) && $relationType == 'morphTo')
            throw new \InvalidArgumentException("Relation '".$relationName."' on model '".get_called_class().' is a morphTo relation and should not contain additional arguments.');

        switch ($relationType) {
            case 'hasOne':
            case 'hasMany':
                $relation = $this->validateRelationArgs($relationName, ['primaryKey', 'localKey']);
                return $this->$relationType($relation[0], $relation['primaryKey'], $relation['localKey'], $relationName);

            case 'belongsTo':
                $relation = $this->validateRelationArgs($relationName, ['foreignKey', 'parentKey']);
                return $this->$relationType($relation[0], $relation['foreignKey'], $relation['parentKey'], $relationName);

            case 'belongsToMany':
                $relation = $this->validateRelationArgs($relationName, ['table', 'primaryKey', 'foreignKey', 'pivotData']);
                $relationObj = $this->$relationType($relation[0], $relation['table'], $relation['primaryKey'], $relation['foreignKey'], $relationName);
                if ($relation['pivotData']) $relationObj->withPivot($relation['pivotData']);
                return $relationObj;

            case 'morphTo':
                $relation = $this->validateRelationArgs($relationName, ['name', 'type', 'id']);
                return $this->$relationType($relation['name'], $relation['type'], $relation['id']);

            case 'morphOne':
            case 'morphMany':
                $relation = $this->validateRelationArgs($relationName, ['type', 'id', 'localKey'], ['name']);
                return $this->$relationType($relation[0], $relation['name'], $relation['type'], $relation['id'], $relation['localKey'], $relationName);

            case 'attachOne':
            case 'attachMany':
                $relation = $this->validateRelationArgs($relationName, ['public', 'localKey']);
                return $this->$relationType($relation[0], $relation['public'], $relation['localKey'], $relationName);

            case 'hasManyThrough':
                $relation = $this->validateRelationArgs($relationName, ['primaryKey', 'throughKey'], ['through']);
                return $this->$relationType($relation[0], $relation['through'], $relation['primaryKey'], $relation['throughKey']);
        }
    }

    /**
     * Validate relation supplied arguments.
     */
    private function validateRelationArgs($relationName, $optional, $required = [])
    {
        $relation = $this->getRelationDefinition($relationName);

        foreach ($optional as $key) {
            if (!array_key_exists($key, $relation)) {
                $relation[$key] = null;
            }
        }

        $missingRequired = [];
        foreach ($required as $key) {
            if (!array_key_exists($key, $relation)) {
                $missingRequired[] = $key;
            }
        }

        if ($missingRequired)
            throw new \InvalidArgumentException("Relation '".$relationName."' on model '".get_called_class().' should contain the following key(s): '.join(', ', $missingRequired));

        return $relation;
    }

    /**
     * Define an polymorphic, inverse one-to-one or many relationship.
     * Overridden from {@link Eloquent\Model} to allow the usage of the intermediary methods to handle the relation.
     * @return \October\Rain\Database\Relations\BelongsTo
     */
    public function morphTo($name = null, $type = null, $id = null)
    {
        if (is_null($name))
            $name = snake_case($this->getRelationCaller());

        list($type, $id) = $this->getMorphs($name, $type, $id);
        $class = $this->$type;

        return $this->belongsTo($class, $id);
    }

    /**
     * Define a one-to-one relationship.
     * This code is a duplicate of Eloquent but uses a Rain relation class.
     * @return \October\Rain\Database\Relations\HasOne
     */
    public function hasOne($related, $primaryKey = null, $localKey = null, $relationName = null)
    {
        if (is_null($relationName))
            $relationName = $this->getRelationCaller();

        $primaryKey = $primaryKey ?: $this->getForeignKey();
        $localKey = $localKey ?: $this->getKeyName();
        $instance = new $related;

        return new HasOne($instance->newQuery(), $this, $instance->getTable().'.'.$primaryKey, $localKey, $relationName);
    }

    /**
     * Define a polymorphic one-to-one relationship.
     * This code is a duplicate of Eloquent but uses a Rain relation class.
     * @return \October\Rain\Database\Relations\MorphOne
     */
    public function morphOne($related, $name, $type = null, $id = null, $localKey = null, $relationName = null)
    {
        if (is_null($relationName))
            $relationName = $this->getRelationCaller();

        $instance = new $related;
        list($type, $id) = $this->getMorphs($name, $type, $id);
        $table = $instance->getTable();
        $localKey = $localKey ?: $this->getKeyName();

        return new MorphOne($instance->newQuery(), $this, $table.'.'.$type, $table.'.'.$id, $localKey, $relationName);
    }

    /**
     * Define an inverse one-to-one or many relationship.
     * Overridden from {@link Eloquent\Model} to allow the usage of the intermediary methods to handle the {@link
     * $relationsData} array.
     * @return \October\Rain\Database\Relations\BelongsTo
     */
    public function belongsTo($related, $foreignKey = null, $parentKey = null, $relationName = null)
    {
        if (is_null($relationName))
            $relationName = $this->getRelationCaller();

        if (is_null($foreignKey))
            $foreignKey = snake_case($relationName).'_id';

        $instance = new $related;
        $query = $instance->newQuery();
        $parentKey = $parentKey ?: $instance->getKeyName();

        return new BelongsTo($query, $this, $foreignKey, $parentKey, $relationName);
    }

    /**
     * Define a one-to-many relationship.
     * This code is a duplicate of Eloquent but uses a Rain relation class.
     * @return \October\Rain\Database\Relations\HasMany
     */
    public function hasMany($related, $primaryKey = null, $localKey = null, $relationName = null)
    {
        if (is_null($relationName))
            $relationName = $this->getRelationCaller();

        $primaryKey = $primaryKey ?: $this->getForeignKey();
        $localKey = $localKey ?: $this->getKeyName();
        $instance = new $related;

        return new HasMany($instance->newQuery(), $this, $instance->getTable().'.'.$primaryKey, $localKey, $relationName);
    }

    /**
     * Define a has-many-through relationship.
     * This code is a duplicate of Eloquent but uses a Rain relation class.
     * @return \October\Rain\Database\Relations\HasMany
     */
    public function hasManyThrough($related, $through, $primaryKey = null, $throughKey = null)
    {
        return new HasManyThrough($related, $through, $primaryKey, $throughKey);
    }

    /**
     * Define a polymorphic one-to-many relationship.
     * This code is a duplicate of Eloquent but uses a Rain relation class.
     * @return \October\Rain\Database\Relations\MorphMany
     */
    public function morphMany($related, $name, $type = null, $id = null, $localKey = null, $relationName = null)
    {
        if (is_null($relationName))
            $relationName = $this->getRelationCaller();

        $instance = new $related;
        list($type, $id) = $this->getMorphs($name, $type, $id);
        $table = $instance->getTable();
        $localKey = $localKey ?: $this->getKeyName();

        return new MorphMany($instance->newQuery(), $this, $table.'.'.$type, $table.'.'.$id, $localKey, $relationName);
    }

    /**
     * Define a many-to-many relationship.
     * This code is a duplicate of Eloquent but uses a Rain relation class.
     * @return \October\Rain\Database\Relations\BelongsToMany
     */
    public function belongsToMany($related, $table = null, $primaryKey = null, $foreignKey = null, $relationName = null)
    {
        if (is_null($relationName))
            $relationName = $this->getRelationCaller();

        $primaryKey = $primaryKey ?: $this->getForeignKey();
        $instance = new $related;
        $foreignKey = $foreignKey ?: $instance->getForeignKey();

        if (is_null($table))
            $table = $this->joiningTable($related);

        $query = $instance->newQuery();
        return new BelongsToMany($query, $this, $table, $primaryKey, $foreignKey, $relationName);
    }

    /**
     * Define an attachment one-to-many relationship.
     * This code is a duplicate of Eloquent but uses a Rain relation class.
     * @return \October\Rain\Database\Relations\MorphMany
     */
    public function attachMany($related, $isPublic = null, $localKey = null, $relationName = null)
    {
        if (is_null($relationName))
            $relationName = $this->getRelationCaller();

        $instance = new $related;
        list($type, $id) = $this->getMorphs('attachment', null, null);
        $table = $instance->getTable();
        $localKey = $localKey ?: $this->getKeyName();

        return new AttachMany($instance->newQuery(), $this, $table.'.'.$type, $table.'.'.$id, $isPublic, $localKey, $relationName);
    }

    /**
     * Define an attachment one-to-one relationship.
     * This code is a duplicate of Eloquent but uses a Rain relation class.
     * @return \October\Rain\Database\Relations\MorphOne
     */
    public function attachOne($related, $isPublic = true, $localKey = null, $relationName = null)
    {
        if (is_null($relationName))
            $relationName = $this->getRelationCaller();

        $instance = new $related;
        list($type, $id) = $this->getMorphs('attachment', null, null);
        $table = $instance->getTable();
        $localKey = $localKey ?: $this->getKeyName();

        return new AttachOne($instance->newQuery(), $this, $table.'.'.$type, $table.'.'.$id, $isPublic, $localKey, $relationName);
    }

    /**
     * Finds the calling function name from the stack trace.
     */
    protected function getRelationCaller()
    {
        $backtrace = debug_backtrace(false);
        $caller = ($backtrace[2]['function'] == 'handleRelation') ? $backtrace[4] : $backtrace[2];
        return $caller['function'];
    }

    /**
     * Sets a relation value directly from its attribute.
     */
    protected function setRelationValue($relationName, $value)
    {
        $relationType = $this->getRelationType($relationName);
        $relationObj = $this->$relationName();

        switch ($relationType) {

            case 'belongsToMany':
                if (!is_array($value)) $value = [$value];

                // Do not sync until the model is saved
                $this->bindOnce('model.afterSave', function() use ($relationObj, $value){
                    $relationObj->sync($value);
                });
                break;

            case 'belongsTo':
                if ($value instanceof EloquentModel) {
                    /*
                     * Non existent model, use a single serve event to associate it again when ready
                     */
                    if (!$value->exists) {
                        $value->bindOnce('model.afterSave', function() use ($relationObj, $value){
                            $relationObj->associate($value);
                        });
                    }

                    $relationObj->associate($value);
                }
                else
                    $this->setAttribute($relationObj->getForeignKey(), $value);
                break;

            case 'attachOne':
            case 'attachMany':
                if ($value instanceof UploadedFile)
                    $relationObj->create(['data' => $value]);
                break;
        }
    }

    /**
     * Eager loads relationships and joins them to a query
     */
    public function joinWith($relations)
    {
        if (is_string($relations)) $relations = func_get_args();

        foreach ($relations as $index => $relation) {
            if (!$this->hasRelation($relation))
                unset($relations[$index]);
        }

        $result = $this->with($relations);

        foreach ($relations as $relation) {
            $relationObj = $this->$relation();
            $relationObj->joinWithQuery($result);
        }

        return $result;
    }

    //
    // Validation
    //

    /**
     * Instantiates the validator used by the validation process, depending if the class is being used inside or
     * outside of Laravel.
     * @return \Illuminate\Validation\Validator
     */
    protected static function makeValidator($data, $rules, $customMessages) 
    {
        return Validator::make($data, $rules, $customMessages);
    }

    /**
     * Validate the model instance
     * @return bool
     */
    public function validate(array $rules = array(), array $customMessages = array())
    {
        if ($this->fireModelEvent('validating') === false) {
            if ($this->throwOnValidation)
                throw new ModelException($this);
            else
                return false;
        }

        /*
         * Perform validation
         */
        $rules = (empty($rules)) ? $this->rules : $rules;
        $rules = $this->processValidationRules($rules);
        $success = true;

        if (!empty($rules)) {
            $data = array_merge($this->getAttributes(), $this->getOriginalHashValues());
            $customMessages = (empty($customMessages)) ? $this->customMessages : $customMessages;
            $validator = self::makeValidator($data, $rules, $customMessages);
            $success = $validator->passes();

            if ($success) {
                if ($this->validationErrors->count() > 0)
                    $this->validationErrors = new MessageBag;
            } else {
                $this->validationErrors = $validator->messages();
                if (Input::hasSession())
                    Input::flash();
            }
        }

        $this->fireModelEvent('validated', false);

        if (!$success && $this->throwOnValidation)
            throw new ModelException($this);

        return $success;
    }

    /**
     * Process rules
     */
    private function processValidationRules($rules)
    {
        foreach ($rules as $field => $ruleParts) {
            /*
             * Trim empty rules
             */
            if (trim($ruleParts) == '') {
                unset($rules[$field]);
                continue;
            }

            /*
             * Normalize rulesets
             */
            if (!is_array($ruleParts))
                $ruleParts = explode('|', $ruleParts);

            /*
             * Analyse each rule individually
             */
            foreach ($ruleParts as $key => $rulePart) {
                /*
                 * Remove primary key unique validation rule if the model already exists
                 */
                if (starts_with($rulePart, 'unique') && $this->exists) {
                    $ruleParts[$key] = 'unique:'.$this->getTable().','.$field.','.$this->getKey();
                }
                /*
                 * Look for required:create and required:update rules
                 */
                else if (starts_with($rulePart, 'required:create') && $this->exists) {
                    unset($ruleParts[$key]);
                }
                else if (starts_with($rulePart, 'required:update') && !$this->exists) {
                    unset($ruleParts[$key]);
                }
            }

            $rules[$field] = $ruleParts;
        }

        return $rules;
    }

    /**
     * Get validation error message collection for the Model
     * @return \Illuminate\Support\MessageBag
     */
    public function errors()
    {
        return $this->validationErrors;
    }

    //
    // Saving
    //

    /**
     * Save the model to the database. Is used by {@link save()} and {@link forceSave()}.
     * @return bool
     */
    protected function saveInternal($data = [], $options = [])
    {
        $force = array_get($options, 'force', false);

        if ($data !== null)
            $this->fill($data);

        if (!$force)
            $valid = $this->validate();

        if ($force || $valid) {

            // Remove any purge attributes from the data set
            $this->purgeAttributes();

            // Save the record
            $result = parent::save($options);

            // Apply any deferred bindings
            if ($this->sessionKey !== null)
                $this->commitDeferred($this->sessionKey);

            return $result;
        }
        else
            return false;
    }

    /**
     * Save the model to the database.
     * @return bool
     */
    public function save(array $data = null, $sessionKey = null)
    {
        $this->sessionKey = $sessionKey;
        return $this->saveInternal($data, ['force' => false]);
    }

    /**
     * Force save the model even if validation fails.
     * @return bool
     */
    public function forceSave(array $data = null, $sessionKey = null)
    {
        $this->sessionKey = $sessionKey;
        return $this->saveInternal($data, ['force' => true]);
    }

    //
    // Deferred binding
    //

    /**
     * Bind a deferred relationship to the supplied record.
     */
    public function bindDeferred($relation, $record, $sessionKey)
    {
        $binding = DeferredBinding::make();
        $binding->master_type = get_class($this);
        $binding->master_field = $relation;
        $binding->slave_type = get_class($record);
        $binding->slave_id = $record->getKey();
        $binding->session_key = $sessionKey;
        $binding->bind = true;
        $binding->save();
        return $binding;
    }

    /**
     * Unbind a deferred relationship to the supplied record.
     */
    public function unbindDeferred($relation, $record, $sessionKey)
    {
        $binding = DeferredBinding::make();
        $binding->master_type = get_class($this);
        $binding->master_field = $relation;
        $binding->slave_type = get_class($record);
        $binding->slave_id = $record->getKey();
        $binding->session_key = $sessionKey;
        $binding->bind = false;
        $binding->save();
        return $binding;
    }

    /**
     * Cancel all deferred bindings to this model.
     */
    public function cancelDeferred($sessionKey)
    {
        DeferredBinding::cancelDeferredActions(get_class($this), $sessionKey);
    }

    /**
     * Commit all deferred bindings to this model.
     */
    public function commitDeferred($sessionKey)
    {
        if (!strlen($sessionKey))
            return;

        $bindings = DeferredBinding::where('master_type', get_class($this))
            ->where('session_key', $sessionKey)
            ->get();

        foreach ($bindings as $binding) {

            if (!($relationName = $binding->master_field))
                continue;

            if (!$this->isDeferrable($relationName))
                continue;

            /*
             * Find the slave model
             */
            $slaveClass = $binding->slave_type;
            $slaveModel = new $slaveClass();
            $slaveModel = $slaveModel->find($binding->slave_id);
            if (!$slaveModel)
                continue;

            /*
             * Bind/Unbind the relationship, save the related model with any
             * deferred bindings it might have and delete the binding action
             */
            $relationObj = $this->$relationName();

            if ($binding->bind)
                $relationObj->add($slaveModel);
            else
                $relationObj->remove($slaveModel);

            $slaveModel->save(null, $sessionKey);

            $binding->delete();
        }
    }

    /**
     * Returns true if a relation exists and can be deferred.
     */
    public function isDeferrable($relationName)
    {
        if (!$this->hasRelation($relationName))
            return false;

        $type = $this->getRelationType($relationName);
        return (
            $type == 'hasMany' ||
            $type == 'hasOne' ||
            $type == 'morphMany' ||
            $type == 'morphOne' ||
            $type == 'attachMany' ||
            $type == 'attachOne' ||
            $type == 'belongsToMany'
        );
    }

    //
    // Getters
    //

    /**
     * Get an attribute from the model.
     * Overrided from {@link Eloquent} to implement recognition of the relation.
     * @return mixed
     */
    public function getAttribute($key)
    {
        // Before Event
        if (($attr = $this->trigger('model.beforeGetAttribute', $key)) !== null)
            return is_array($attr) ? reset($attr) : $attr;

        $attr = parent::getAttribute($key);

        if ($attr === null) {
            if ($this->hasRelation($key)) {
                $this->relations[$key] = $this->$key()->getResults();
                return $this->relations[$key];
            }
        }

        // Handle jsonable
        if (in_array($key, $this->jsonable) && !empty($attr)) {
            if ($value = json_decode($attr, true))
                $attr = $value;
        }

        // After Event
        if (($_attr = $this->trigger('model.afterGetAttribute', $key, $attr)) !== null)
            return is_array($_attr) ? reset($_attr) : $_attr;

        return $attr;
    }

    /**
     * Determine if a get mutator exists for an attribute.
     * @param  string  $key
     * @return bool
     */
    public function hasGetMutator($key)
    {
        return $this->methodExists('get'.studly_case($key).'Attribute');
    }

    //
    // Setters
    //

    /**
     * Set a given attribute on the model.
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function setAttribute($key, $value)
    {
        // Before Event
        if ($this->trigger('model.beforeSetAttribute', $key, $value) === false)
            return;

        // Hash required fields when necessary
        if (in_array($key, $this->hashable) && !empty($value)) {
            $this->originalHashableValues[$key] = $value;
            $value = Hash::make($value);
        }

        // Handle jsonable
        if (in_array($key, $this->jsonable) && !empty($value)) {
            $value = json_encode($value);
        }

        // Handle direct relation setting
        if ($this->hasRelation($key)) {
            $result = $this->setRelationValue($key, $value);
        }
        else {
            $result = parent::setAttribute($key, $value);
        }


        // After Event
        $this->trigger('model.afterSetAttribute', $key, $value);

        return $result;
    }

    /**
     * Determine if a set mutator exists for an attribute.
     * @param  string  $key
     * @return bool
     */
    public function hasSetMutator($key)
    {
        return $this->methodExists('set'.studly_case($key).'Attribute');
    }

    //
    // Hashable
    //

    /**
     * Returns a collection of fields that will be hashed.
     */
    public function getHashableAttributes()
    {
        return $this->hashable;
    }

    /**
     * Returns the original values of any hashed attributes.
     */
    public function getOriginalHashValues()
    {
        return $this->originalHashableValues;
    }

    /**
     * Returns the original values of any hashed attributes.
     */
    public function getOriginalHashValue($attribute)
    {
        return isset($this->originalHashableValues[$attribute])
            ? $this->originalHashableValues[$attribute]
            : null;
    }

    //
    // Purgable
    //

    /**
     * Removes purged attributes from the dataset, used before saving.
     * @param $attributes mixed Attribute(s) to purge, if unspecified, $purgable property is used
     * @return array Current attribute set
     */
    public function purgeAttributes($attributesToPurge = null)
    {
        if ($attributesToPurge !== null)
            $purgeable = is_array($attributesToPurge) ? $attributesToPurge : [$attributesToPurge];
        else
            $purgeable = $this->getPurgeableAttributes();

        $attributes = $this->getAttributes();
        $cleanAttributes = array_diff_key($attributes, array_flip($purgeable));
        $originalAttributes = array_diff_key($attributes, $cleanAttributes);

        if (is_array($this->originalPurgeableValues))
            $this->originalPurgeableValues = array_merge($this->originalPurgeableValues, $originalAttributes);
        else
            $this->originalPurgeableValues = $originalAttributes;

        return $this->attributes = $cleanAttributes;
    }

    /**
     * Returns a collection of fields that will be hased.
     */
    public function getPurgeableAttributes()
    {
        return $this->purgeable;
    }

    /**
     * Returns the original values of any purged attributes.
     */
    public function getOriginalPurgeValues()
    {
        return $this->originalPurgeableValues;
    }

    /**
     * Returns the original values of any purged attributes.
     */
    public function getOriginalPurgeValue($attribute)
    {
        return isset($this->originalPurgeableValues[$attribute])
            ? $this->originalPurgeableValues[$attribute]
            : null;
    }

    /**
     * Restores the original values of any purged attributes.
     */
    public function restorePurgedValues()
    {
        $this->attributes = array_merge($this->getAttributes(), $this->originalPurgeableValues);
        return $this;
    }

}
