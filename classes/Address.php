<?php

/**
 * Delivery addresses ("Anwani za Usafirishaji"). Each customer sees only their own.
 * The first address becomes the default; there is always exactly one default while any exist.
 * (Orders will copy the address when placed, so editing or deleting it never changes a past order.)
 *
 * How to use it:
 *   $address_model = new Address(Database::instance());
 *   $address_model->createAddress($user_id, $input);    // → the saved address
 */
class Address
{
    private const MAX_ADDRESSES = 20;

    private const RULES = [
        'address_recipient_name' => 'required|string|min:2|max:100',
        'address_phone'          => 'required|phone_tz',
        'region_id'              => 'required|int|min:1',
        'district_id'            => 'nullable|int|min:1',
        'address_street'         => 'required|string|min:3|max:160',
        'address_landmark'       => 'nullable|string|max:160',
        'address_is_default'     => 'nullable|bool',
    ];

    public function __construct(private Database $db)
    {
    }

    /** The customer's addresses, the default one first. */
    public function getAddresses(int $user_id): array
    {
        $rows = $this->db->fetchAll(
            $this->selectSql() . ' WHERE a.user_id = :user_id ORDER BY a.address_is_default DESC, a.address_id DESC',
            ['user_id' => $user_id]
        );
        return array_map(fn (array $row) => $this->formatAddress($row), $rows);
    }

    /** One of the customer's addresses, or 404 (also when it belongs to someone else). */
    public function getAddress(int $user_id, int $address_id): array
    {
        $row = $this->db->fetchOne(
            $this->selectSql() . ' WHERE a.address_id = :address_id AND a.user_id = :user_id',
            ['address_id' => $address_id, 'user_id' => $user_id]
        );
        if ($row === null) {
            throw ApiException::notFound('Anwani haikupatikana.');
        }
        return $this->formatAddress($row);
    }

    public function createAddress(int $user_id, array $input): array
    {
        $data = $this->validateAddress($input);

        $address_count = (int) $this->db->fetchValue('SELECT COUNT(*) FROM addresses WHERE user_id = :user_id', ['user_id' => $user_id]);
        if ($address_count >= self::MAX_ADDRESSES) {
            throw ApiException::validation(['address_street' => 'Umefikia idadi ya juu ya anwani (' . self::MAX_ADDRESSES . ').']);
        }

        $make_default = $address_count === 0 || ($data['address_is_default'] ?? false);

        $address_id = $this->db->transaction(function () use ($user_id, $data, $make_default) {
            if ($make_default) {
                $this->clearDefault($user_id);
            }
            return $this->db->insert(
                'INSERT INTO addresses (
                    user_id, address_recipient_name, address_phone, region_id, district_id,
                    address_street, address_landmark, address_is_default
                 ) VALUES (
                    :user_id, :address_recipient_name, :address_phone, :region_id, :district_id,
                    :address_street, :address_landmark, :address_is_default
                 )',
                ['user_id' => $user_id, 'address_is_default' => (int) $make_default] + $this->columnValues($data)
            );
        });

        return $this->getAddress($user_id, $address_id);
    }

    public function updateAddress(int $user_id, int $address_id, array $input): array
    {
        $current_address = $this->getAddress($user_id, $address_id); // 404 if not theirs
        $data = $this->validateAddress($input);

        // Unticking "default" on the default address is ignored: another one must be chosen instead
        $make_default = $current_address['address_is_default'] || ($data['address_is_default'] ?? false);

        $this->db->transaction(function () use ($user_id, $address_id, $data, $make_default) {
            if ($make_default) {
                $this->clearDefault($user_id);
            }
            $this->db->execute(
                'UPDATE addresses
                 SET address_recipient_name = :address_recipient_name, address_phone = :address_phone,
                     region_id = :region_id, district_id = :district_id, address_street = :address_street,
                     address_landmark = :address_landmark, address_is_default = :address_is_default
                 WHERE address_id = :address_id AND user_id = :user_id',
                ['address_id' => $address_id, 'user_id' => $user_id, 'address_is_default' => (int) $make_default]
                    + $this->columnValues($data)
            );
        });

        return $this->getAddress($user_id, $address_id);
    }

    /** Makes this address the default one. Returns all addresses (default first). */
    public function setDefaultAddress(int $user_id, int $address_id): array
    {
        $this->getAddress($user_id, $address_id); // 404 if not theirs

        $this->db->transaction(function () use ($user_id, $address_id) {
            $this->clearDefault($user_id);
            $this->db->execute(
                'UPDATE addresses SET address_is_default = 1 WHERE address_id = :address_id AND user_id = :user_id',
                ['address_id' => $address_id, 'user_id' => $user_id]
            );
        });

        return $this->getAddresses($user_id);
    }

    /** Deletes an address. If it was the default, the newest remaining one becomes the default. */
    public function deleteAddress(int $user_id, int $address_id): array
    {
        $address = $this->getAddress($user_id, $address_id); // 404 if not theirs

        $this->db->transaction(function () use ($user_id, $address_id, $address) {
            $this->db->execute(
                'DELETE FROM addresses WHERE address_id = :address_id AND user_id = :user_id',
                ['address_id' => $address_id, 'user_id' => $user_id]
            );
            if ($address['address_is_default']) {
                $this->db->execute(
                    'UPDATE addresses SET address_is_default = 1 WHERE user_id = :user_id ORDER BY address_id DESC LIMIT 1',
                    ['user_id' => $user_id]
                );
            }
        });

        return $this->getAddresses($user_id);
    }

    private function validateAddress(array $input): array
    {
        $data = Validator::validate($input, self::RULES);
        (new Region($this->db))->checkLocation($data['region_id'], $data['district_id'] ?? null);
        return $data;
    }

    private function clearDefault(int $user_id): void
    {
        $this->db->execute('UPDATE addresses SET address_is_default = 0 WHERE user_id = :user_id', ['user_id' => $user_id]);
    }

    private function columnValues(array $data): array
    {
        return [
            'address_recipient_name' => $data['address_recipient_name'],
            'address_phone'          => $data['address_phone'],
            'region_id'              => $data['region_id'],
            'district_id'            => $data['district_id'] ?? null,
            'address_street'         => $data['address_street'],
            'address_landmark'       => $data['address_landmark'] ?? null,
        ];
    }

    private function selectSql(): string
    {
        return 'SELECT a.*, r.region_name, d.district_name
                FROM addresses a
                JOIN regions r ON r.region_id = a.region_id
                LEFT JOIN districts d ON d.district_id = a.district_id';
    }

    private function formatAddress(array $row): array
    {
        return [
            'address_id'             => (int) $row['address_id'],
            'address_recipient_name' => $row['address_recipient_name'],
            'address_phone'          => $row['address_phone'],
            'region_id'              => (int) $row['region_id'],
            'region_name'            => $row['region_name'],
            'district_id'            => $row['district_id'] === null ? null : (int) $row['district_id'],
            'district_name'          => $row['district_name'],
            'address_street'         => $row['address_street'],
            'address_landmark'       => $row['address_landmark'],
            'address_is_default'     => (bool) $row['address_is_default'],
        ];
    }
}
