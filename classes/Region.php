<?php

/**
 * Tanzania's regions (Mkoa) and districts (Wilaya) for the registration and address forms.
 */
class Region
{
    public function __construct(private Database $db)
    {
    }

    public function getAllRegions(): array
    {
        return $this->db->fetchAll('SELECT region_id, region_name FROM regions ORDER BY region_name');
    }

    /** Districts of one region. Throws 404 if the region does not exist. */
    public function getDistrictsByRegion(int $region_id): array
    {
        if (!$this->regionExists($region_id)) {
            throw ApiException::notFound('Mkoa huu haupo.');
        }

        return $this->db->fetchAll(
            'SELECT district_id, district_name FROM districts WHERE region_id = :region_id ORDER BY district_name',
            ['region_id' => $region_id]
        );
    }

    /** Throws a 422 unless the region exists and the district (if chosen) belongs to it. Used by profiles and addresses. */
    public function checkLocation(int $region_id, ?int $district_id): void
    {
        if (!$this->regionExists($region_id)) {
            throw ApiException::validation(['region_id' => 'Chagua mkoa sahihi.']);
        }
        if ($district_id !== null && !$this->districtBelongsToRegion($district_id, $region_id)) {
            throw ApiException::validation(['district_id' => 'Wilaya hii haipo kwenye mkoa uliochagua.']);
        }
    }

    public function regionExists(int $region_id): bool
    {
        return (bool) $this->db->fetchValue('SELECT 1 FROM regions WHERE region_id = :region_id', ['region_id' => $region_id]);
    }

    public function districtBelongsToRegion(int $district_id, int $region_id): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM districts WHERE district_id = :district_id AND region_id = :region_id',
            ['district_id' => $district_id, 'region_id' => $region_id]
        );
    }
}
