<?php

/**
 * RawWire Socrata SODA API Client
 *
 * Fetches building permit data from LA City's Socrata Open Data API.
 * Supports multiple datasets with field mapping normalization.
 *
 * Primary: pi9x-tg5x — Building Permits Issued 2020-Present
 * Secondary: xnhu-aczu — LA BUILD PERMITS (contractor/applicant details)
 *            9yda-i4ya — EBEWE Green Building Program
 *            gwh9-jnip — Submitted Permits (early pipeline)
 *
 * @package RawWire_Dashboard
 * @since   1.0.27
 */

if (! defined('ABSPATH')) {
    exit;
}

class RawWire_Socrata_Client
{

    /** @var string Base API domain */
    const API_DOMAIN = 'https://data.lacity.org';

    /** @var string */
    private $app_token;

    /** @var string */
    private $secret_token;

    /** @var int Request timeout in seconds */
    private $timeout = 60;

    /**
     * Dataset column map — normalize different datasets to our schema
     */
    const DATASET_MAP = array(
        // Primary: Building Permits Issued 2020-Present
        'pi9x-tg5x' => array(
            'id_field'          => 'permit_nbr',
            'address_field'     => 'primary_address',
            'type_field'        => 'permit_type',
            'sub_type_field'    => 'permit_sub_type',
            'use_code_field'    => 'use_code',
            'use_desc_field'    => 'use_desc',
            'work_desc_field'   => 'work_desc',
            'valuation_field'   => 'valuation',
            'sqft_field'        => 'square_footage',
            'height_field'      => 'height',
            'status_field'      => 'status_desc',
            'status_date_field' => 'status_date',
            'submitted_field'   => 'submitted_date',
            'issue_date_field'  => 'issue_date',
            'cofo_date_field'   => 'cofo_date',
            'zip_field'         => 'zip_code',
            'cd_field'          => 'cd',
            'zone_field'        => 'zone',
            'cpa_field'         => 'cpa',
            'cnc_field'         => 'cnc',
            'lat_field'         => 'lat',
            'lon_field'         => 'lon',
            'solar_field'       => 'solar',
            'ev_field'          => 'ev',
            'construction_field' => 'construction',
            'unit_field'        => 'business_unit',
        ),
        // LA BUILD PERMITS (has contractor/applicant data)
        'xnhu-aczu' => array(
            'id_field'          => 'pcis_permit',
            'address_field'     => 'address_start', // Composite: address_start + street_direction + street_name + street_suffix
            'type_field'        => 'permit_type',
            'sub_type_field'    => 'permit_sub_type',
            'use_code_field'    => 'use_code',
            'use_desc_field'    => 'use_desc',
            'work_desc_field'   => 'work_desc',
            'valuation_field'   => 'valuation',
            'sqft_field'        => 'floor_area_l_a_zoning_code_definition',
            'height_field'      => null,
            'status_field'      => 'status',
            'status_date_field' => 'status_date',
            'submitted_field'   => null,
            'issue_date_field'  => 'issue_date',
            'cofo_date_field'   => null,
            'zip_field'         => 'zip_code',
            'cd_field'          => 'council_district',
            'zone_field'        => 'zone',
            'cpa_field'         => 'community_plan_area',
            'cnc_field'         => 'census_tract',
            'lat_field'         => 'latitude',
            'lon_field'         => 'longitude',
            'solar_field'       => null,
            'ev_field'          => null,
            'construction_field' => null,
            'unit_field'        => 'permit_category',
            // Extra fields
            'contractor_field'  => 'contractors_business_name',
            'license_field'     => 'license',
            'stories_field'     => 'of_stories',
            'applicant_first'   => 'applicant_first_name',
            'applicant_last'    => 'applicant_last_name',
            'applicant_biz'     => 'applicant_business_name',
        ),
        // EBEWE — Energy/Water Efficiency
        '9yda-i4ya' => array(
            'id_field'          => 'building_id',
            'address_field'     => 'building_address',
            'zip_field'         => 'postal_code',
            'special_type'      => 'ebewe',
        ),
        // Submitted Permits 2020-Present
        'gwh9-jnip' => array(
            'id_field'          => 'permit_nbr',
            'address_field'     => 'primary_address',
            'type_field'        => 'permit_type',
            'sub_type_field'    => 'permit_sub_type',
            'use_desc_field'    => 'use_desc',
            'work_desc_field'   => 'work_desc',
            'valuation_field'   => 'valuation',
            'sqft_field'        => 'square_footage',
            'status_field'      => 'status_desc',
            'submitted_field'   => 'submitted_date',
            'issue_date_field'  => 'issue_date',
            'zip_field'         => 'zip_code',
            'lat_field'         => 'lat',
            'lon_field'         => 'lon',
            'solar_field'       => 'solar',
            'ev_field'          => 'ev',
        ),
        // New Commercial Building Permits >$100K (filtered view)
        'hhzr-9ttq' => array(
            'id_field'          => 'permit_nbr',
            'address_field'     => 'primary_address',
            'type_field'        => 'permit_type',
            'sub_type_field'    => 'permit_sub_type',
            'use_code_field'    => 'use_code',
            'use_desc_field'    => 'use_desc',
            'work_desc_field'   => 'work_desc',
            'valuation_field'   => 'valuation',
            'sqft_field'        => 'square_footage',
            'status_field'      => 'status_desc',
            'issue_date_field'  => 'issue_date',
            'zip_field'         => 'zip_code',
            'lat_field'         => 'lat',
            'lon_field'         => 'lon',
        ),
        // Apartment Building Permits >$100K (filtered view)
        'n3m5-kxzy' => array(
            'id_field'          => 'permit_nbr',
            'address_field'     => 'primary_address',
            'type_field'        => 'permit_type',
            'sub_type_field'    => 'permit_sub_type',
            'use_code_field'    => 'use_code',
            'use_desc_field'    => 'use_desc',
            'work_desc_field'   => 'work_desc',
            'valuation_field'   => 'valuation',
            'sqft_field'        => 'square_footage',
            'status_field'      => 'status_desc',
            'issue_date_field'  => 'issue_date',
            'zip_field'         => 'zip_code',
            'lat_field'         => 'lat',
            'lon_field'         => 'lon',
        ),
    );

    /**
     * @param string $app_token    Socrata app token
     * @param string $secret_token Socrata secret token (optional for read access)
     */
    public function __construct($app_token, $secret_token = '')
    {
        $this->app_token    = $app_token;
        $this->secret_token = $secret_token;
    }

    /**
     * Fetch building permits with filtering
     *
     * @param array $params Filter parameters for SoQL query building.
     * @return array|WP_Error
     */
    public function fetch_permits($params = array())
    {
        $defaults = array(
            'dataset'               => 'pi9x-tg5x',
            'limit'                 => 500,
            'offset'                => 0,
            'permit_types'          => array('Bldg-New', 'Bldg-Alter/Repair', 'Bldg-Addition'),
            'permit_sub_types'      => array('Commercial', 'Apartment'),
            'min_valuation'         => 100000,
            'max_valuation'         => 0,
            'min_sqft'              => 0,
            'date_months'           => 2,
            'sort_by'               => 'issue_date',
            'sort_order'            => 'DESC',
            // Location
            'zip_codes'             => '',
            'council_districts'     => '',
            'community_plan_area'   => '',
            'zone'                  => '',
            // Use & status
            'use_desc_search'       => '',
            'use_code'              => '',
            'work_desc_search'      => '',
            'status_filter'         => '',
            // Green / specialty
            'solar_only'            => false,
            'ev_only'               => false,
            'construction_type'     => '',
            // Contractor (xnhu-aczu)
            'require_party_data'    => false,
            'exclude_owner_builder' => false,
            'contractor_name_search' => '',
            'license_search'        => '',
        );
        $params = wp_parse_args($params, $defaults);

        $where_clauses = $this->build_where_clauses($params);

        // Determine sort field — resolve to dataset-specific column name
        $order_field = $this->resolve_field($params['dataset'], $params['sort_by']);
        $order_dir   = strtoupper($params['sort_order']) === 'ASC' ? 'ASC' : 'DESC';

        $query_params = array(
            '$limit'  => $params['limit'],
            '$offset' => $params['offset'],
            '$order'  => "{$order_field} {$order_dir}",
        );

        if (! empty($where_clauses)) {
            $query_params['$where'] = implode(' AND ', $where_clauses);
        }

        return $this->request($params['dataset'], $query_params);
    }

    /**
     * Resolve a logical field name to the dataset-specific Socrata column.
     *
     * @param string $dataset Dataset ID
     * @param string $logical Logical name (e.g. 'issue_date', 'valuation', 'zip_code')
     * @return string Socrata column name (falls back to logical name)
     */
    private function resolve_field($dataset, $logical)
    {
        $field_key_map = array(
            'issue_date'     => 'issue_date_field',
            'submitted_date' => 'submitted_field',
            'status_date'    => 'status_date_field',
            'valuation'      => 'valuation_field',
            'zip_code'       => 'zip_field',
            'council_district' => 'cd_field',
            'community_plan_area' => 'cpa_field',
            'zone'           => 'zone_field',
            'use_code'       => 'use_code_field',
            'use_desc'       => 'use_desc_field',
            'work_desc'      => 'work_desc_field',
            'status_desc'    => 'status_field',
            'square_footage' => 'sqft_field',
            'solar'          => 'solar_field',
            'ev'             => 'ev_field',
            'construction'   => 'construction_field',
        );

        $map = self::DATASET_MAP[$dataset] ?? array();
        $key = $field_key_map[$logical] ?? null;

        if ($key && ! empty($map[$key])) {
            return $map[$key];
        }

        return $logical; // fallback: use the logical name as-is
    }

    /**
     * Build SoQL WHERE clauses from filter params.
     *
     * Shared by fetch_permits() and count_permits().
     *
     * @param array $params Merged params
     * @return array WHERE clause strings
     */
    private function build_where_clauses($params)
    {
        $where_clauses = array();
        $ds = $params['dataset'];

        // --- Permit type filter ---
        if (! empty($params['permit_types'])) {
            $conds = array();
            foreach ($params['permit_types'] as $t) {
                $conds[] = "permit_type='" . addslashes($t) . "'";
            }
            $where_clauses[] = '(' . implode(' OR ', $conds) . ')';
        }

        // --- Permit sub-type filter ---
        if (! empty($params['permit_sub_types'])) {
            $conds = array();
            foreach ($params['permit_sub_types'] as $st) {
                $conds[] = "permit_sub_type='" . addslashes($st) . "'";
            }
            $where_clauses[] = '(' . implode(' OR ', $conds) . ')';
        }

        // --- Valuation range (::number cast for text-typed Socrata columns) ---
        $val_col = $this->resolve_field($ds, 'valuation');
        if (! empty($params['min_valuation']) && $params['min_valuation'] > 0) {
            $where_clauses[] = "{$val_col}::number > " . intval($params['min_valuation']);
        }
        if (! empty($params['max_valuation']) && $params['max_valuation'] > 0) {
            $where_clauses[] = "{$val_col}::number < " . intval($params['max_valuation']);
        }

        // --- Square footage (::number cast for text-typed Socrata columns) ---
        $sqft_col = $this->resolve_field($ds, 'square_footage');
        if (! empty($params['min_sqft']) && $params['min_sqft'] > 0) {
            $where_clauses[] = "{$sqft_col}::number > " . intval($params['min_sqft']);
        }

        // --- Date filter (issue_date recency) ---
        if (! empty($params['date_months']) && $params['date_months'] > 0) {
            $cutoff = date('Y-m-d', strtotime("-{$params['date_months']} months"));
            $date_field = ($ds === 'gwh9-jnip') ? 'submitted_date' : 'issue_date';
            $date_col = $this->resolve_field($ds, $date_field);
            $where_clauses[] = "{$date_col} >= '{$cutoff}T00:00:00'";
        }

        // --- Max permit age filter (submitted_date — excludes ancient projects) ---
        if (! empty($params['max_permit_age_months']) && $params['max_permit_age_months'] > 0) {
            $age_cutoff = date('Y-m-d', strtotime("-{$params['max_permit_age_months']} months"));
            $submit_col = $this->resolve_field($ds, 'submitted_date');
            if ($submit_col) {
                $where_clauses[] = "{$submit_col} >= '{$age_cutoff}T00:00:00'";
            }
        }

        // --- Location filters ---
        if (! empty($params['zip_codes'])) {
            $zips = array_map('trim', explode(',', $params['zip_codes']));
            $zip_col = $this->resolve_field($ds, 'zip_code');
            $conds = array();
            foreach ($zips as $z) {
                if ($z !== '') {
                    $conds[] = "{$zip_col}='" . addslashes($z) . "'";
                }
            }
            if ($conds) {
                $where_clauses[] = '(' . implode(' OR ', $conds) . ')';
            }
        }

        if (! empty($params['council_districts'])) {
            $cds = array_map('trim', explode(',', $params['council_districts']));
            $cd_col = $this->resolve_field($ds, 'council_district');
            $conds = array();
            foreach ($cds as $cd) {
                if ($cd !== '') {
                    $conds[] = "{$cd_col}='" . addslashes($cd) . "'";
                }
            }
            if ($conds) {
                $where_clauses[] = '(' . implode(' OR ', $conds) . ')';
            }
        }

        if (! empty($params['community_plan_area'])) {
            $cpa_col = $this->resolve_field($ds, 'community_plan_area');
            $where_clauses[] = "upper({$cpa_col}) like upper('%" . addslashes($params['community_plan_area']) . "%')";
        }

        if (! empty($params['zone'])) {
            $zone_col = $this->resolve_field($ds, 'zone');
            $where_clauses[] = "upper({$zone_col}) like upper('%" . addslashes($params['zone']) . "%')";
        }

        // --- Use & status filters ---
        if (! empty($params['use_desc_search'])) {
            $use_col = $this->resolve_field($ds, 'use_desc');
            $where_clauses[] = "upper({$use_col}) like upper('%" . addslashes($params['use_desc_search']) . "%')";
        }

        if (! empty($params['use_code'])) {
            $codes = array_map('trim', explode(',', $params['use_code']));
            $uc_col = $this->resolve_field($ds, 'use_code');
            $conds = array();
            foreach ($codes as $c) {
                if ($c !== '') {
                    $conds[] = "{$uc_col}='" . addslashes($c) . "'";
                }
            }
            if ($conds) {
                $where_clauses[] = '(' . implode(' OR ', $conds) . ')';
            }
        }

        if (! empty($params['work_desc_search'])) {
            $wd_col = $this->resolve_field($ds, 'work_desc');
            $where_clauses[] = "upper({$wd_col}) like upper('%" . addslashes($params['work_desc_search']) . "%')";
        }

        if (! empty($params['status_filter'])) {
            $st_col = $this->resolve_field($ds, 'status_desc');
            $where_clauses[] = "{$st_col}='" . addslashes($params['status_filter']) . "'";
        }

        // --- Green / specialty ---
        if (! empty($params['solar_only'])) {
            $sol_col = $this->resolve_field($ds, 'solar');
            $where_clauses[] = "{$sol_col}='Y'";
        }
        if (! empty($params['ev_only'])) {
            $ev_col = $this->resolve_field($ds, 'ev');
            $where_clauses[] = "{$ev_col}='Y'";
        }
        if (! empty($params['construction_type'])) {
            $ct_col = $this->resolve_field($ds, 'construction');
            $where_clauses[] = "upper({$ct_col}) like upper('%" . addslashes($params['construction_type']) . "%')";
        }

        // --- Contractor filters (xnhu-aczu specific) ---
        if ($ds === 'xnhu-aczu') {
            if (! empty($params['require_party_data'])) {
                $where_clauses[] = "contractors_business_name IS NOT NULL";
            }
            if (! empty($params['exclude_owner_builder'])) {
                $where_clauses[] = "contractors_business_name != 'OWNER-BUILDER'";
            }
            if (! empty($params['contractor_name_search'])) {
                $where_clauses[] = "upper(contractors_business_name) like upper('%" . addslashes($params['contractor_name_search']) . "%')";
            }
            if (! empty($params['license_search'])) {
                $where_clauses[] = "license='" . addslashes($params['license_search']) . "'";
            }
        }

        return $where_clauses;
    }

    /**
     * Count available records matching filters (for preview)
     *
     * @param array $params Same as fetch_permits
     * @return int|WP_Error
     */
    public function count_permits($params = array())
    {
        $defaults = array(
            'dataset'               => 'pi9x-tg5x',
            'permit_types'          => array('Bldg-New', 'Bldg-Alter/Repair', 'Bldg-Addition'),
            'permit_sub_types'      => array('Commercial', 'Apartment'),
            'min_valuation'         => 100000,
            'max_valuation'         => 0,
            'min_sqft'              => 0,
            'date_months'           => 6,
            'zip_codes'             => '',
            'council_districts'     => '',
            'community_plan_area'   => '',
            'zone'                  => '',
            'use_desc_search'       => '',
            'use_code'              => '',
            'work_desc_search'      => '',
            'status_filter'         => '',
            'solar_only'            => false,
            'ev_only'               => false,
            'construction_type'     => '',
            'require_party_data'    => false,
            'exclude_owner_builder' => false,
            'contractor_name_search' => '',
            'license_search'        => '',
        );
        $params = wp_parse_args($params, $defaults);

        $where_clauses = $this->build_where_clauses($params);

        $query_params = array('$select' => 'count(*) as cnt');
        if (! empty($where_clauses)) {
            $query_params['$where'] = implode(' AND ', $where_clauses);
        }

        $result = $this->request($params['dataset'], $query_params);
        if (is_wp_error($result)) {
            return $result;
        }

        return isset($result[0]['cnt']) ? intval($result[0]['cnt']) : 0;
    }

    /**
     * Fetch EBEWE green building records for cross-referencing
     *
     * @param string $zip_code Filter by zip
     * @param int    $limit    Max records
     * @return array|WP_Error
     */
    public function fetch_ebewe($zip_code = '', $limit = 500)
    {
        $query = array('$limit' => $limit);
        if ($zip_code) {
            $query['$where'] = "postal_code='" . addslashes($zip_code) . "'";
        }
        return $this->request('9yda-i4ya', $query);
    }

    /**
     * Fetch party data for a permit from xnhu-aczu dataset
     *
     * This dataset contains contractor, applicant, and owner information
     * that is NOT available in the primary pi9x-tg5x dataset.
     *
     * @param string $permit_nbr Permit number to look up
     * @return array|null Party data or null if not found
     */
    public function fetch_party_data(string $permit_nbr): ?array
    {
        $cache_key = 'rw_party_' . md5($permit_nbr);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached ?: null;
        }

        $query = array(
            '$where' => "pcis_permit='" . addslashes($permit_nbr) . "'",
            '$limit' => 1,
        );

        $result = $this->request('xnhu-aczu', $query);

        if (is_wp_error($result) || empty($result)) {
            set_transient($cache_key, '', 3600); // Cache miss for 1 hour
            return null;
        }

        $record = $result[0];
        $party_data = array(
            'contractor_name'     => $record['contractors_business_name'] ?? '',
            'contractor_license'  => $record['license'] ?? '',
            'license_type'        => $record['license_type'] ?? '',
            'principal_name'      => trim(($record['principal_first_name'] ?? '') . ' ' . ($record['principal_last_name'] ?? '')),
            'applicant_name'      => trim(($record['applicant_first_name'] ?? '') . ' ' . ($record['applicant_last_name'] ?? '')),
            'applicant_business'  => $record['applicant_business_name'] ?? '',
            'contractor_address'  => $record['contractor_address'] ?? '',
            'contractor_city'     => $record['contractor_city'] ?? '',
            'contractor_state'    => $record['contractor_state'] ?? '',
        );

        set_transient($cache_key, $party_data, 86400); // Cache for 24 hours
        return $party_data;
    }

    /**
     * Bulk fetch party data for multiple permits
     *
     * @param array $permit_nbrs Array of permit numbers
     * @return array Keyed by permit_nbr
     */
    public function fetch_party_data_bulk(array $permit_nbrs): array
    {
        if (empty($permit_nbrs)) {
            return array();
        }

        // Build OR clause for multiple permits
        $conditions = array_map(function ($p) {
            return "pcis_permit='" . addslashes($p) . "'";
        }, array_slice($permit_nbrs, 0, 50)); // Limit to 50 at a time

        $query = array(
            '$where' => '(' . implode(' OR ', $conditions) . ')',
            '$limit' => 50,
        );

        $result = $this->request('xnhu-aczu', $query);

        if (is_wp_error($result)) {
            rawwire_log('socrata', 'Bulk party fetch failed: ' . $result->get_error_message(), 'error');
            return array();
        }

        $party_map = array();
        foreach ($result as $record) {
            $permit = $record['pcis_permit'] ?? '';
            if ($permit) {
                $party_map[$permit] = array(
                    'contractor_name'     => $record['contractors_business_name'] ?? '',
                    'contractor_license'  => $record['license'] ?? '',
                    'license_type'        => $record['license_type'] ?? '',
                    'principal_name'      => trim(($record['principal_first_name'] ?? '') . ' ' . ($record['principal_last_name'] ?? '')),
                    'applicant_name'      => trim(($record['applicant_first_name'] ?? '') . ' ' . ($record['applicant_last_name'] ?? '')),
                    'applicant_business'  => $record['applicant_business_name'] ?? '',
                    'contractor_address'  => $record['contractor_address'] ?? '',
                    'contractor_city'     => $record['contractor_city'] ?? '',
                    'contractor_state'    => $record['contractor_state'] ?? '',
                );
            }
        }

        return $party_map;
    }

    /**
     * Map a raw Socrata record to our normalized schema
     *
     * @param array  $record Raw API record
     * @param string $dataset Dataset ID
     * @return array Mapped record for insertion
     */
    public function map_record($record, $dataset)
    {
        $map = isset(self::DATASET_MAP[$dataset]) ? self::DATASET_MAP[$dataset] : self::DATASET_MAP['pi9x-tg5x'];

        $mapped = array(
            'source_api'         => 'socrata_permits',
            'source_dataset'     => $dataset,
            'source_record_id'   => $this->get_field($record, $map['id_field']),
            'permit_nbr'         => $this->get_field($record, $map['id_field']),
            'primary_address'    => $this->build_address($record, $dataset),
            'zip_code'           => $this->get_field($record, isset($map['zip_field']) ? $map['zip_field'] : 'zip_code'),
            'council_district'   => $this->get_field($record, isset($map['cd_field']) ? $map['cd_field'] : ''),
            'permit_type'        => $this->get_field($record, isset($map['type_field']) ? $map['type_field'] : ''),
            'permit_sub_type'    => $this->get_field($record, isset($map['sub_type_field']) ? $map['sub_type_field'] : ''),
            'use_code'           => $this->get_field($record, isset($map['use_code_field']) ? $map['use_code_field'] : ''),
            'use_desc'           => $this->get_field($record, isset($map['use_desc_field']) ? $map['use_desc_field'] : ''),
            'work_desc'          => $this->get_field($record, isset($map['work_desc_field']) ? $map['work_desc_field'] : ''),
            'valuation'          => floatval($this->get_field($record, isset($map['valuation_field']) ? $map['valuation_field'] : '')),
            'square_footage'     => intval($this->get_field($record, isset($map['sqft_field']) ? $map['sqft_field'] : '')),
            'height'             => $this->get_field($record, isset($map['height_field']) ? $map['height_field'] : ''),
            'construction_type'  => $this->get_field($record, isset($map['construction_field']) ? $map['construction_field'] : ''),
            'status_desc'        => $this->get_field($record, isset($map['status_field']) ? $map['status_field'] : ''),
            'zone'               => $this->get_field($record, isset($map['zone_field']) ? $map['zone_field'] : ''),
            'community_plan_area' => $this->get_field($record, isset($map['cpa_field']) ? $map['cpa_field'] : ''),
            'neighborhood_council' => $this->get_field($record, isset($map['cnc_field']) ? $map['cnc_field'] : ''),
            'solar'              => $this->get_field($record, isset($map['solar_field']) ? $map['solar_field'] : ''),
            'ev'                 => $this->get_field($record, isset($map['ev_field']) ? $map['ev_field'] : ''),
            'business_unit'      => $this->get_field($record, isset($map['unit_field']) ? $map['unit_field'] : ''),
        );

        // Lat/Lon
        $lat = $this->get_field($record, isset($map['lat_field']) ? $map['lat_field'] : '');
        $lon = $this->get_field($record, isset($map['lon_field']) ? $map['lon_field'] : '');
        $mapped['lat'] = $lat ? floatval($lat) : null;
        $mapped['lon'] = $lon ? floatval($lon) : null;

        // Dates
        $mapped['status_date']    = $this->parse_date($this->get_field($record, isset($map['status_date_field']) ? $map['status_date_field'] : ''));
        $mapped['submitted_date'] = $this->parse_date($this->get_field($record, isset($map['submitted_field']) ? $map['submitted_field'] : ''));
        $mapped['issue_date']     = $this->parse_date($this->get_field($record, isset($map['issue_date_field']) ? $map['issue_date_field'] : ''));
        $mapped['cofo_date']      = $this->parse_date($this->get_field($record, isset($map['cofo_date_field']) ? $map['cofo_date_field'] : ''));

        // Contractor/applicant (only in xnhu-aczu)
        if (isset($map['contractor_field'])) {
            $mapped['contractor_name'] = $this->get_field($record, $map['contractor_field']);
        }
        if (isset($map['license_field'])) {
            $mapped['contractor_license'] = $this->get_field($record, $map['license_field']);
        }
        if (isset($map['stories_field'])) {
            $mapped['stories'] = $this->get_field($record, $map['stories_field']);
        }
        if (isset($map['applicant_first'])) {
            $first = $this->get_field($record, $map['applicant_first']);
            $last  = $this->get_field($record, $map['applicant_last']);
            $mapped['applicant_name'] = trim($first . ' ' . $last);
        }
        if (isset($map['applicant_biz'])) {
            $mapped['applicant_business'] = $this->get_field($record, $map['applicant_biz']);
        }

        // Tag generation
        $tags = array();
        if (! empty($mapped['permit_type']))     $tags[] = $mapped['permit_type'];
        if (! empty($mapped['permit_sub_type']))  $tags[] = $mapped['permit_sub_type'];
        if (! empty($mapped['use_desc']))         $tags[] = $mapped['use_desc'];
        if ($mapped['solar'] === 'Y')               $tags[] = 'solar';
        if ($mapped['ev'] === 'Y')                  $tags[] = 'ev';
        if ($mapped['valuation'] >= 1000000)        $tags[] = 'high-value';
        if ($mapped['valuation'] >= 5000000)        $tags[] = 'mega-project';
        $mapped['tags'] = implode(',', $tags);

        return $mapped;
    }

    /**
     * Make a SODA API request
     *
     * @param string $dataset Dataset ID
     * @param array  $query   SoQL query parameters
     * @return array|WP_Error
     */
    private function request($dataset, $query = array())
    {
        $url = self::API_DOMAIN . '/resource/' . $dataset . '.json';

        // Add app token only if configured (anonymous access is rate limited but works)
        if (! empty($this->app_token)) {
            $query['$$app_token'] = $this->app_token;
        }

        $url = add_query_arg($query, $url);

        $headers = array(
            'Accept' => 'application/json',
        );

        // Add basic auth if secret token provided
        if (! empty($this->secret_token) && ! empty($this->app_token)) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->app_token . ':' . $this->secret_token);
        }

        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'timeout' => $this->timeout,
        ));

        if (is_wp_error($response)) {
            rawwire_log('socrata', 'API request failed: ' . $response->get_error_message(), 'error');
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // If 403 with credentials, retry anonymously — bad/expired tokens block public datasets
        if ($code === 403 && (! empty($this->app_token) || ! empty($this->secret_token))) {
            rawwire_log('socrata', 'Got 403 with credentials — retrying anonymously', 'warning');

            // Strip auth: rebuild URL without $$app_token, drop auth header
            $anon_query = $query;
            unset($anon_query['$$app_token']);
            $anon_url = add_query_arg($anon_query, self::API_DOMAIN . '/resource/' . $dataset . '.json');

            $response = wp_remote_get($anon_url, array(
                'headers' => array('Accept' => 'application/json'),
                'timeout' => $this->timeout,
            ));

            if (is_wp_error($response)) {
                rawwire_log('socrata', 'Anonymous retry failed: ' . $response->get_error_message(), 'error');
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
        }

        if ($code !== 200) {
            $error_msg = sprintf('Socrata API error %d: %s', $code, substr($body, 0, 500));
            rawwire_log('socrata', $error_msg, 'error');
            return new WP_Error('socrata_error', $error_msg);
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'Failed to parse Socrata response.');
        }

        rawwire_log('socrata', sprintf(
            'Fetched %d records from dataset %s',
            count($data),
            $dataset
        ));

        return $data;
    }

    /**
     * Safely extract a field from a record
     */
    private function get_field($record, $field)
    {
        if (empty($field) || ! isset($record[$field])) {
            return '';
        }
        return $record[$field];
    }

    /**
     * Parse a Socrata date string to MySQL datetime
     */
    private function parse_date($date_str)
    {
        if (empty($date_str)) {
            return null;
        }
        // Socrata returns ISO 8601: "2025-01-15T00:00:00.000"
        $ts = strtotime($date_str);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    /**
     * Get available datasets info
     */
    public static function get_datasets()
    {
        return array(
            'pi9x-tg5x' => array(
                'name'   => 'Building Permits Issued 2020-Present',
                'desc'   => 'Official LADBS dataset, updated weekly. Best for current permits.',
                'domain' => 'data.lacity.org',
            ),
            'xnhu-aczu' => array(
                'name'   => 'LA BUILD PERMITS',
                'desc'   => 'Community view with contractor/applicant details. Nov 2015+.',
                'domain' => 'data.lacity.org',
            ),
            'gwh9-jnip' => array(
                'name'   => 'Building Permits Submitted 2020-Present',
                'desc'   => 'Submitted (pre-approval) permits — earliest pipeline stage.',
                'domain' => 'data.lacity.org',
            ),
            '9yda-i4ya' => array(
                'name'   => 'EBEWE Green Building Program',
                'desc'   => 'Energy/water efficiency compliance for large buildings.',
                'domain' => 'data.lacity.org',
            ),
            'hhzr-9ttq' => array(
                'name'   => 'New Commercial Building Permits >$100K',
                'desc'   => 'Pre-filtered commercial new construction.',
                'domain' => 'data.lacity.org',
            ),
            'n3m5-kxzy' => array(
                'name'   => 'Apartment Building Permits >$100K',
                'desc'   => 'Pre-filtered apartment construction.',
                'domain' => 'data.lacity.org',
            ),
        );
    }

    /**
     * Build full address from composite fields (xnhu-aczu)
     *
     * @param array  $record  Raw API record
     * @param string $dataset Dataset ID
     * @return string Full address
     */
    private function build_address($record, $dataset)
    {
        $map = isset(self::DATASET_MAP[$dataset]) ? self::DATASET_MAP[$dataset] : self::DATASET_MAP['pi9x-tg5x'];

        // For xnhu-aczu, compose address from parts
        if ($dataset === 'xnhu-aczu') {
            $parts = array();

            // Address number(s)
            $start = $this->get_field($record, 'address_start');
            $end = $this->get_field($record, 'address_end');
            if ($start) {
                $parts[] = ($end && $end !== $start) ? "{$start}-{$end}" : $start;
            }

            // Street direction (N, S, E, W)
            $dir = $this->get_field($record, 'street_direction');
            if ($dir) {
                $parts[] = $dir;
            }

            // Street name
            $name = $this->get_field($record, 'street_name');
            if ($name) {
                $parts[] = $name;
            }

            // Street suffix (ST, AVE, BLVD, etc.)
            $suffix = $this->get_field($record, 'street_suffix');
            if ($suffix) {
                $parts[] = $suffix;
            }

            return trim(implode(' ', $parts));
        }

        // Default: use address_field directly
        return $this->get_field($record, $map['address_field']);
    }
}
