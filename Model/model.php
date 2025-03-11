<?php

namespace MobbexSubscription;

abstract class Model
{
    /** @var wpdb */
    public $db;

    public $data;
    public $table;
    public $logger;
    public $update;
    public $fillable;
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
        $this->logger = new \Mobbex\WP\Checkout\Model\Logger();
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
        
        $this->logger->maybe_log_error('MobbexSubscription\Model > get data error: ', []);
        return isset($result[0]) ? $result[0] : null;
    }

    public function save($data)
    {
        if($this->update){
            $this->db->update($this->db->prefix.$this->table, $data, [$this->primary_key => $data[$this->primary_key]]);

            if (empty($this->db->last_error))
                return true;

            $this->logger->maybe_log_error('MobbexSubscription\Model > save error: ', $data);
            return false;

        } else {
            $this->db->insert($this->db->prefix.$this->table, $data);

            if (empty($this->db->last_error))
                return true;

            $this->logger->maybe_log_error('MobbexSubscription\Model > save error: ', $data);
            return false;
        }
    }
}
