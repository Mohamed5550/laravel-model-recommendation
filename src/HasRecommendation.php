<?php

namespace Umutphp\LaravelModelRecommendation;

use Umutphp\LaravelModelRecommendation\RecommendationsModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * Trait class
 */
trait HasRecommendation
{
    /**
     * Generate recommendations and save it to the table. The table should be generated by using the migration.
     * The function uses co-occurrence of models in data table under same group to make a recommendation.
     *
     * @param string $name Name of the recommendation set
     *
     * @return void
     */
    public static function generateRecommendations($name)
    {
        $config = self::getRecommendationConfig()[$name] ?? null;

        logger("Started generating recommendation for $name");

        if ($config === null) {
            logger()->error("No configuration for $name");
            return;
        }

        $recommendations = self::getData($config);

        foreach ($recommendations as $data1 => $data) {
            RecommendationsModel::where('source_type', self::class)
                ->where('source_id', $data1)
                ->where('recommendation_name', $name)
                ->delete();

            foreach ($data as $data2 => $order) {
                $recommendation = new RecommendationsModel(
                    [
                        'source_type'         => self::class,
                        'source_id'           => $data1,
                        'target_type'         => $config['recommendation_data_field_type'] ?? self::class,
                        'target_id'           => $data2,
                        'order_column'        => $order,
                        'recommendation_name' => $name
                    ]
                );
                $recommendation->save();
            }
        }

        logger("Finished generating recommendation for $name");
    }

    /**
     * Get data from resource according to the config
     *
     * @param array $config
     *
     * @return iterable|object
     */
    public static function getData($config)
    {
        $algoritm        = $config['recommendation_algorithm'] ?? 'db_relation';
        $recommendations = [];

        logger("The algoritm is $algoritm");

        if ($algoritm == 'db_relation') {
            $data = DB::table($config['recommendation_data_table'])
                ->select(
                    $config['recommendation_group_field'] . ' as group_field',
                    $config['recommendation_data_field'] . ' as data_field'
                );

            if (is_array($config['recommendation_data_table_filter'])) {
                foreach ($config['recommendation_data_table_filter'] as $field => $value) {
                    $data = $data->where($field, $value);
                }
            }

            logger("The query to fetch data: " . $data->toSql());

            $data  = $data->get();
            $count = $config['recommendation_count'] ?? config('laravel_model_recommendation.recommendation_count');

            $recommendations = self::calculateRecommendations($data, $count);
        }

        if ($algoritm == 'similarity') {
            $data = self::all();

            $recommendations = self::calculateSimilarityMatrix($data, $config);
        }

        return $recommendations;
    }

    /**
     * Calculate recommendations
     *
     * @param Collection $data
     * @param int        $dataCount
     *
     * @return Collection
     */
    public static function calculateRecommendations($data, $dataCount)
    {
        $dataCartesianRanks = [];
        $recommendations    = [];
        $dataGroup          = [];

        foreach ($data as $value) {
            if (!isset($dataGroup[$value->group_field])) {
                $dataGroup[$value->group_field] = [];
            }

            $dataGroup[$value->group_field][$value->data_field] = $value->data_field;
        }

        foreach ($dataGroup as $group) {
            foreach ($group as $data1) {
                foreach ($group as $data2) {
                    if ($data1 == $data2) {
                        continue;
                    }

                    if (!isset($dataCartesianRanks[$data1])) {
                        $dataCartesianRanks[$data1] = [];
                    }

                    if (!isset($dataCartesianRanks[$data1][$data2])) {
                        $dataCartesianRanks[$data1][$data2] = 0;
                    }

                    $dataCartesianRanks[$data1][$data2] += 1;
                }
            }
        }

        // Generate recommendation list by sorting
        foreach ($dataCartesianRanks as $data1 => $data) {
            arsort($data);

            $key = key($dataGroup);

            $data                    = array_slice($data, 0, $dataCount, true);
            $recommendations[$key] = $data;
        }

        return $recommendations;
    }

    /**
     * Calculate similarity between model objects.
     *
     * @param Object $model1
     * @param Object $model2
     * @param arrau  $config
     *
     * @return void
     */
    public static function calculateSimilarityScore($model1, $model2, $config)
    {
        $featureWeight         = $config['similarity_feature_weight'] ?? 1;
        $numericValueWeight    = $config['similarity_numeric_value_weight'] ?? 1;
        $taxonomyWeight        = $config['similarity_taxonomy_weight'] ?? 1;
        $numericValueHighRange = $config['similarity_numeric_value_high_range'] ?? 1000;
        $featureFields         = $config['similarity_feature_attributes'] ?? [];
        $taxonomyFields        = $config['similarity_taxonomy_attributes'] ?? [];
        $numericFields         = $config['similarity_numeric_value_attributes'] ?? [];
        $model1Features        = implode(
            '',
            array_filter($model1->toArray(), function ($k) use ($featureFields) {
                return in_array($k, $featureFields);
            }, ARRAY_FILTER_USE_KEY)
        );
        $model2Features        = implode(
            '',
            array_filter($model2->toArray(), function ($k) use ($featureFields) {
                return in_array($k, $featureFields);
            }, ARRAY_FILTER_USE_KEY)
        );
        $model1Taxonomies      = implode(',', self::generateTaxonomies($model1, $taxonomyFields));
        $model2Taxonomies      = implode(',', self::generateTaxonomies($model2, $taxonomyFields));

        $return   = [];
        $return[] = (SimilarityHelper::hamming($model1Features, $model2Features) * $featureWeight);

        $numericFields1 = [];
        $numericFields2 = [];

        foreach ($numericFields as $field) {
            $numericField1 = 0;

            if (array_key_exists($field, $model1->toArray())) {
                $numericField1 = $model1->$field;
            }

            $numericField2 = 0;

            if (array_key_exists($field, $model2->toArray())) {
                $numericField2 = $model2->$field;
            }

            $numericFields1[] = $numericField1;
            $numericFields2[] = $numericField2;
        }

        $return[] = (SimilarityHelper::euclidean(
            SimilarityHelper::minMaxNorm($numericFields1, 0, $numericValueHighRange),
            SimilarityHelper::minMaxNorm($numericFields2, 0, $numericValueHighRange)
        ) * $numericValueWeight);

        $return[] = (SimilarityHelper::jaccard($model1Taxonomies, $model2Taxonomies, ',') * $taxonomyWeight);

        return (array_sum($return) / ($featureWeight + $numericValueWeight + $taxonomyWeight)) * 100;
    }

    /**
     * Generate taxonomy array
     *
     * @param [type] $model
     * @param [type] $taxonomyFields
     *
     * @return array
     */
    public static function generateTaxonomies($model, $taxonomyFields): array
    {
        $modelTaxonomies = [];

        foreach ($taxonomyFields as $fields) {
            foreach ($fields as $field => $subField) {
                if (is_object($model->$field) && ($model->$field instanceof Collection)) {
                    foreach ($model->$field as $object) {
                        if (property_exists($object, $subField)) {
                            $modelTaxonomies[] = $object->$subField;
                        } else {
                            $modelTaxonomies[] = '';
                        }
                    }
                } elseif (is_object($model->$field)) {
                    if (property_exists($model->$field, $subField)) {
                        $modelTaxonomies[] = $model->$field->$subField;
                    } else {
                        $modelTaxonomies[] = '';
                    }
                } else {
                    $modelTaxonomies[] = (string) $model->$field;
                }
            }
        }

        return $modelTaxonomies;
    }

    /**
     * Calculate the similarity cartesian matrix
     *
     * @return array
     */
    public static function calculateSimilarityMatrix($models, $config): array
    {
        $matrix = [];
        $return = [];
        $count  = $config['recommendation_count'] ?? config('laravel_model_recommendation.recommendation_count');

        foreach ($models as $model1) {
            $similarityScores = [];

            foreach ($models as $model2) {
                if ($model1->id === $model2->id) {
                    continue;
                }
                $similarityScores[$model2->id] = self::calculateSimilarityScore($model1, $model2, $config);
            }

            $matrix[$model1->id] = $similarityScores;
        }

        // Generate recommendation list by sorting
        foreach ($matrix as $data1 => $data) {
            arsort($data);

            $data                    = array_slice($data, 0, $count, true);
            $return[$data1] = $data;
        }

        return $return;
    }

    /**
     * Return the list of recommended models
     *
     * @param string $name Name of the recommendation set
     *
     * @return Collection
     */
    public function getRecommendations($name)
    {
        $config = $this->getRecommendationConfig()[$name] ?? null;
        $model = $config['recommendation_data_field_type'] ?? self::class;

        if ($config === null) {
            return [];
        }

        $recommendations = RecommendationsModel::where('source_type', self::class)
            ->where('recommendation_name', $name)
            ->where('target_type', $model)
            ->where('source_id', $this->id)
            ->get();


        $return = $model::query()->whereIn('id', $recommendations->pluck('target_id'))->get();

        $order = $config['recommendation_order'] ?? config('laravel_model_recommendation.recommendation_count');

        if ($order == 'asc') {
            return $return->reverse();
        }

        if ($order == 'random') {
            $random = $return->shuffle();

            return collect($random->all());
        }

        return $return;
    }

    /**
     * Return the list of recommended models with relationships
     *
     * @param string $name Name of the recommendation set
     * @param array $relationships Relationships that should be loaded with the recommendations
     *
     * @return Collection
     */
    public function getRecommendationsWithRelationships($name, $relationships)
    {
        $models = $this->getRecommendations($name);
        $models->load($relationships);

        return $models;
    }
}
