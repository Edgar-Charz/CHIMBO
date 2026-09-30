<?php

/**
 * Tanzania's 31 regions (Mkoa) and their districts (Wilaya) for the registration dropdowns.
 * Safe to run again: existing rows are skipped (INSERT IGNORE + unique keys).
 *
 * Note: district lists change from time to time (new councils are created). Check against the
 * official list before launch; admins will be able to correct them later.
 */

$regions_and_districts = [
    'Arusha'          => ['Arusha City', 'Arusha', 'Karatu', 'Longido', 'Meru', 'Monduli', 'Ngorongoro'],
    'Dar es Salaam'   => ['Ilala', 'Kinondoni', 'Temeke', 'Kigamboni', 'Ubungo'],
    'Dodoma'          => ['Dodoma City', 'Bahi', 'Chamwino', 'Chemba', 'Kondoa', 'Kongwa', 'Mpwapwa'],
    'Geita'           => ['Geita', 'Bukombe', 'Chato', 'Mbogwe', "Nyang'hwale"],
    'Iringa'          => ['Iringa Urban', 'Iringa Rural', 'Kilolo', 'Mufindi'],
    'Kagera'          => ['Bukoba Urban', 'Bukoba Rural', 'Biharamulo', 'Karagwe', 'Kyerwa', 'Missenyi', 'Muleba', 'Ngara'],
    'Katavi'          => ['Mpanda', 'Mlele', 'Tanganyika'],
    'Kigoma'          => ['Kigoma Urban', 'Kigoma Rural', 'Buhigwe', 'Kakonko', 'Kasulu', 'Kibondo', 'Uvinza'],
    'Kilimanjaro'     => ['Moshi Urban', 'Moshi Rural', 'Hai', 'Mwanga', 'Rombo', 'Same', 'Siha'],
    'Lindi'           => ['Lindi', 'Mtama', 'Kilwa', 'Liwale', 'Nachingwea', 'Ruangwa'],
    'Manyara'         => ['Babati Urban', 'Babati Rural', 'Hanang', 'Kiteto', 'Mbulu', 'Simanjiro'],
    'Mara'            => ['Musoma Urban', 'Musoma Rural', 'Bunda', 'Butiama', 'Rorya', 'Serengeti', 'Tarime'],
    'Mbeya'           => ['Mbeya City', 'Mbeya Rural', 'Busokelo', 'Chunya', 'Kyela', 'Mbarali', 'Rungwe'],
    'Morogoro'        => ['Morogoro Urban', 'Morogoro Rural', 'Gairo', 'Kilombero', 'Kilosa', 'Malinyi', 'Mvomero', 'Ulanga'],
    'Mtwara'          => ['Mtwara Urban', 'Mtwara Rural', 'Masasi', 'Nanyumbu', 'Newala', 'Tandahimba'],
    'Mwanza'          => ['Ilemela', 'Nyamagana', 'Buchosa', 'Kwimba', 'Magu', 'Misungwi', 'Sengerema', 'Ukerewe'],
    'Njombe'          => ['Njombe Urban', 'Njombe Rural', 'Ludewa', 'Makambako', 'Makete', "Wanging'ombe"],
    'Pwani'           => ['Bagamoyo', 'Chalinze', 'Kibaha Urban', 'Kibaha Rural', 'Kibiti', 'Kisarawe', 'Mafia', 'Mkuranga', 'Rufiji'],
    'Rukwa'           => ['Sumbawanga Urban', 'Sumbawanga Rural', 'Kalambo', 'Nkasi'],
    'Ruvuma'          => ['Songea Urban', 'Songea Rural', 'Madaba', 'Mbinga', 'Namtumbo', 'Nyasa', 'Tunduru'],
    'Shinyanga'       => ['Shinyanga Urban', 'Shinyanga Rural', 'Kahama', 'Kishapu', 'Msalala', 'Ushetu'],
    'Simiyu'          => ['Bariadi', 'Busega', 'Itilima', 'Maswa', 'Meatu'],
    'Singida'         => ['Singida Urban', 'Singida Rural', 'Ikungi', 'Iramba', 'Itigi', 'Manyoni', 'Mkalama'],
    'Songwe'          => ['Ileje', 'Mbozi', 'Momba', 'Songwe', 'Tunduma'],
    'Tabora'          => ['Tabora Urban', 'Igunga', 'Kaliua', 'Nzega', 'Sikonge', 'Urambo', 'Uyui'],
    'Tanga'           => ['Tanga City', 'Bumbuli', 'Handeni', 'Kilindi', 'Korogwe', 'Lushoto', 'Mkinga', 'Muheza', 'Pangani'],
    'Kaskazini Pemba' => ['Micheweni', 'Wete'],
    'Kusini Pemba'    => ['Chake Chake', 'Mkoani'],
    'Kaskazini Unguja'=> ['Kaskazini A', 'Kaskazini B'],
    'Kusini Unguja'   => ['Kati', 'Kusini'],
    'Mjini Magharibi' => ['Mjini', 'Magharibi A', 'Magharibi B'],
];

return function (Database $db) use ($regions_and_districts): void {
    foreach ($regions_and_districts as $region_name => $district_names) {
        $db->execute('INSERT IGNORE INTO regions (region_name) VALUES (:region_name)', ['region_name' => $region_name]);

        $region_id = $db->fetchValue(
            'SELECT region_id FROM regions WHERE region_name = :region_name',
            ['region_name' => $region_name]
        );

        foreach ($district_names as $district_name) {
            $db->execute(
                'INSERT IGNORE INTO districts (region_id, district_name) VALUES (:region_id, :district_name)',
                ['region_id' => $region_id, 'district_name' => $district_name]
            );
        }
    }
};
