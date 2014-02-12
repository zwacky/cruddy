<?php

namespace Kalnoy\Cruddy\Schema\Fields;

use Illuminate\Database\Eloquent\Model as Eloquent;
use Kalnoy\Cruddy\Schema\InlineRelationInterface;
use Kalnoy\Cruddy\OperationNotPermittedException;
use Kalnoy\Cruddy\Service\Validation\ValidationException;

/**
 * Inline relation allows to edit related models inlinely.
 */
abstract class InlineRelation extends BaseRelation implements InlineRelationInterface {

    /**
     * @inheritdoc
     *
     * @var string
     */
    protected $type = 'inline-relation';

    /**
     * Whether the model relates to many items.
     *
     * @var bool
     */
    protected $multiple = false;

    /**
     * @inhertidoc
     *
     * Inline relation skips value since it is passed to the other repository.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public function skip($value)
    {
        return true;
    }

    /**
     * @inheritdoc
     *
     * @param array $input
     *
     * @return array
     */
    public function processInput(array $input)
    {
        if ($this->multiple) return $this->processMany($input);

        return $this->processInputItem($input);
    }

    /**
     * Process many items. This is needed to capture validation errors.
     *
     * @param array $input
     *
     * @return array
     */
    public function processMany(array $input)
    {
        $errors = [];
        $result = [];

        foreach ($input as $cid => $item)
        {
            try 
            {
                $result[] = $this->processInputItem($item);
            } 

            catch (ValidationException $e) 
            {
                // Remember errors by cid since we might be creating new items
                // that don't have an id
                $errors[$cid] = $e->getErrors();
            }
        }

        if ( ! empty($errors)) throw new ValidationException($errors);

        return $result;
    }

    /**
     * Process single item.
     *
     * @param array $item
     *
     * @return array
     */
    public function processInputItem(array $item)
    {
        extract($item);

        $action = empty($id) ? 'create' : 'update';

        list($attributes, $relatedData) = $this->reference->process($action, $attributes);

        return compact('id', 'action', 'attributes', 'relatedData');
    }

    /**
     * @inhertidoc
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param array                               $data
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function save(Eloquent $model, array $data)
    {
        if ( ! $this->multiple) $data = [ $data ];

        $ref    = $this->reference;
        $permit = $ref->getPermissions();
        $repo   = $ref->getRepository();

        // Get current items and check if some needs to be deleted
        $delete = $this->newRelationalQuery($model)->lists('id');
        $ids = [];

        foreach ($data as $item)
        {
            // See the layout @ processInputItem
            extract($item);

            if ( ! $permit[$action]) continue;

            $attributes += $this->getConnectingAttributes($model);

            switch ($action)
            {
                case 'create': $innerModel = $repo->create($attributes); break;
                case 'update': $innerModel = $repo->update($id, $attributes); break;
            }

            // Save related items for inner model.
            $ref->saveRelated($innerModel, $relatedData);

            if ( ! empty($id)) $ids[] = $id;
        }

        if ( ! empty($ids)) $delete = array_diff($delete, $ids);

        if ( ! empty($delete) && $permit['delete']) $repo->delete($delete);
    }

    /**
     * @inheritdoc
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return array
     */
    public function extract(Eloquent $model)
    {
        return $this->reference->extract($model->{$this->id});
    }

}