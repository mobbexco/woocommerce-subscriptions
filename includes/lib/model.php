<?php

namespace Mobbex;

abstract class Model
{
    /** @var wpdb */
    public $db;

    public $table;
    public $primary_key;
    public $array_columns = [];

    /**
     * Instance the model and try to fill properties.
     * 
     * @param mixed ...$props
     */
    public function __construct(...$props)
    {
        //Wp global db connection
        $this->db = $GLOBALS['wpdb'];
        //Errors logger
        $this->logger = new \Mbbxs_Logger();
        //Load the model
        $this->load($props);
        //Fill properties
        $this->fill($props);
    }

    /**
     * Load the transaction in the model.
     */
    private function load($props)
    {
        if (empty($props[0]))
            return $this;

        $params = $this->get_data($props[0]);

        if (empty($params))
            return $this->update = false;

        foreach ($params as $key => $value)
            $this->$key = in_array($key, $this->array_columns) ? json_decode($value, true) : $value;

        return $this->update = true;
    }

    /**
     * Fill properties to current model.
     * 
     * @param mixed $props
     */
    public function fill($props)
    {
        foreach ($props as $key => $value) {
            if (isset($this->fillable[$key]) && $value)
                $this->{$this->fillable[$key]} = $value;
        }
    }

    /**
     * Get data from db table.
     * 
     * @param string $key
     * 
     * @return array|null An asociative array with transaction values.
     */
    public function get_data($key)
    {
        // Make request to db
        $result = $this->db->get_results(
            "SELECT * FROM " . $this->db->prefix . $this->table . " WHERE $this->primary_key=$key LIMIT 1;",
            ARRAY_A
        );
        $this->logger->debug('Abstract Model save error: ' . $this->db->last_error, [], true);
        mbbxs_log('debug', 'Mobbex Model > save => save error: ' . $this->db->last_error, ['wpdb_error' => $this->db->last_error]);
        return isset($result[0]) ? $result[0] : null;
    }

    public function save($data)
    {
        global $wpdb;

        if($this->update){
            $wpdb->update($wpdb->prefix.$this->table, $data, [$this->primary_key => $data[$this->primary_key]]);

            if (empty($wpdb->last_error))
                return true;
            $this->logger->debug('Abstract Model save error: ' . $wpdb->last_error, [], true);
            mbbxs_log('debug', 'Mobbex Model > save => save error: ' . $wpdb->last_error, ['wpdb_error' => $wpdb->last_error]);
            return false;

        } else {
            $wpdb->insert($wpdb->prefix.$this->table, $data);

            if (empty($wpdb->last_error))
                return true;
            $this->logger->debug('Abstract Model save error: ' . $wpdb->last_error, [], true);
            mbbxs_log('debug', 'Mobbex Model > save => save error: ' . $wpdb->last_error, ['wpdb_error' => $wpdb->last_error]);
            return false;
        }
    }
}
