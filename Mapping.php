<?
namespace x1\knockout;

use Yii;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\ArrayHelper;
use yii\web\JsExpression;
use yii\web\View;
use yii\helpers\Url;
use x1\knockout\helpers\DateFormatConverter;

class Mapping extends \yii\base\Widget {

    const DATE                = 'date';
    const THOUSANDS_SEPARATOR = 'thousandsSeparator';
    const DECIMAL_SEPARATOR   = 'decimalSeparator';


    public static $autoIdPrefix   = 'mapping';
    public static $defaultFormats = [];

    public $namespace  = 'mapping';
    public $name       = null;
    public $mapping    = '{}';
    public $model      = null;
    public $formats    = [];
    public $attributes = [];
    public $timeout    = 3000;
    public $duration   = 86400;

    public static function widget($config = []) {
        parent::widget($config);
    }

    /**
     * @inheritdoc
     */
    public function run()
    {
        $view  = $this->getView();
        $cache = Yii::$app->cache;

        // register global prototype per namespace
        if (!($js = $cache->get($id = [self::$autoIdPrefix, 'prototype', $this->namespace]))) {
            $js = $view->render('@x1/knockout/views/mapping/prototype', [
                'namespace'  => $this->namespace,
                ]);
            $cache->set($id, $js, $this->duration);
        }
        $view->registerJs($js, \yii\web\View::POS_END);

        if (!($js = $cache->get($id = [$this->id, 'mapping', Yii::$app->language, $this->namespace, $this->name]))) {


            //
            //  detect validators and type extenders from model definition
            //
            if (!empty($this->model)) {


                $formats    = ArrayHelper::merge(self::$defaultFormats, $this->formats);
                $columns    = method_exists($this->model, 'getTableSchema') ? $this->model->getTableSchema()->columns : [];
                $attributes = array_keys($this->attributes);

                //
                //  create 1:1 relations as observables
                //
                if (!empty($this->attributes)) {
                    foreach ($this->attributes as $key => $value) {
                        $rel = $this->model->getRelation($key, false);
                        if ($rel !== null && !$rel->multiple) {
                            $this->attributes[$key]['observable'] = true;
                        }
                    }
                }


                $attrs      = ArrayHelper::merge(array_keys($this->model->getPrimaryKey(true)), $this->model->safeAttributes());


                // foreach ($this->model->safeAttributes() as $attribute) {
                foreach ($attrs as $attribute) {

                    $a = ArrayHelper::getValue($this->attributes, $attribute, null);
                    if (!empty($a))
                        $a = ArrayHelper::getValue($a, 'validators', null);


                    if (empty($a)) {
                        $model_validators = $this->model->getActiveValidators($attribute);
                 

                        $validators = [];
                        foreach ($model_validators as $validator) {
                            $js = $validator->clientValidateAttribute($this->model, $attribute, $view);
                            if (!empty($js)) {
                                if (!isset($validators[$attribute]))
                                    $validators[$attribute] = [];
                                $validators[$attribute][] = $js;
                            }
                        }
                        $validators = array_filter($validators);


                        if (!empty($validators)) {
                            $this->attributes[$attribute]['validators'] = new JsExpression(sprintf('function(value, messages) {%s}', implode('', $validators[$attribute])));
                        }
                    }
                    
                    // automatic type extender detection
                    if (!in_array($attribute, $attributes) && array_key_exists($attribute, $columns)) {
                        switch($columns[$attribute]->type) {
                            case 'smallint':
                            case 'long':
                            case 'integer':
                            {                   
                                $this->attributes[$attribute]['decimal'] = ['decimals' => 0, 'thousandsSeparator' => $formats['thousandsSeparator']];
                                break;
                            }
                            case 'decimal':
                            {
                                preg_match('|^decimal\(\d+,(\d+)\)$|i', $columns[$attribute]->dbType, $matches);
                                $this->attributes[$attribute]['decimal'] = ['decimals' => $matches[1], 'thousandsSeparator' => $formats['thousandsSeparator'], 'decimalSeparator' => $formats['decimalSeparator']];
                                break;
                            }
                            case 'date':
                            {
                                $this->attributes[$attribute]['date'] = ['format' => DateFormatConverter::convertPhpToMoment($formats['date']), 'time' => false];
                                break;
                            }
                            case 'datetime':
                            {
                                $this->attributes[$attribute]['datetime'] = ['format' => DateFormatConverter::convertPhpToMoment($formats['date']), 'time' => true];
                                break;
                            }
                            default:
                            {
                                $this->attributes[$attribute]['display'] = true;
                                break;
                            }
                        }
                    }

                }
            }



            $js = $view->render('@x1/knockout/views/mapping/mapping', [
                'namespace'  => $this->namespace,
                'name'       => $this->name,
                'mapping'    => $this->mapping,
                'attributes' => $this->attributes,
                'model'      => $this->model,
                ]);
            $cache->set($id, $js, $this->duration);
        }
        $view->registerJs($js, \yii\web\View::POS_END);


        $view->registerAssetBundle('x1\knockout\KnockoutAsset');
    }

    public function init() {
        // current regional settings
        
        $decimal  = ArrayHelper::getValue(self::$defaultFormats, self::DECIMAL_SEPARATOR, null);
        $thousand = ArrayHelper::getValue(self::$defaultFormats, self::THOUSANDS_SEPARATOR, null);
        $date     = ArrayHelper::getValue(self::$defaultFormats, self::DATE, null);


        $locale = Yii::$app->formatter->locale;
        if (empty($locale))
            $locale = Yii::$app->language;


        if ($decimal == null || $thousand == null) {

            if ($decimal == null)
                $decimal = Yii::$app->formatter->decimalSeparator;
            if ($thousand == null)
                $thousand = Yii::$app->formatter->thousandSeparator;

            if ($decimal == null || $thousand == null) {
                $fmt = numfmt_create($locale, \NumberFormatter::DECIMAL);
                if ($decimal == null)
                    $decimal = numfmt_get_symbol($fmt, \NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
                if ($thousand == null)
                    $thousand = numfmt_get_symbol($fmt, \NumberFormatter::GROUPING_SEPARATOR_SYMBOL);
            }

            self::$defaultFormats[self::DECIMAL_SEPARATOR]   = $decimal;
            self::$defaultFormats[self::THOUSANDS_SEPARATOR] = $thousand;
        }


        if ($date == null) {

            $shortFormats = [
                'short'  => 3, // IntlDateFormatter::SHORT,
                'medium' => 2, // IntlDateFormatter::MEDIUM,
                'long'   => 1, // IntlDateFormatter::LONG,
                'full'   => 0, // IntlDateFormatter::FULL,
            ];

            if (extension_loaded('intl')) {
                $fmt        = new \IntlDateFormatter($locale, $shortFormats[Yii::$app->formatter->dateFormat], \IntlDateFormatter::NONE, Yii::$app->formatter->timeZone);
                $dateFormat = $fmt->getPattern();
                $date       = \yii\helpers\FormatConverter::convertDateIcuToPhp($dateFormat);
            } else {
                $date = 'yyyy-MM-dd';
            }

            self::$defaultFormats[self::DATE] = $date;
        }

        if (empty($this->name)) {
            $this->name = empty($this->model) ? $this->id : lcfirst($this->model->formName());
        }

    }
}

?>