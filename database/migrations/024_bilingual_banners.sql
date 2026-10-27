-- Store English banner copy alongside the existing Kiswahili copy.
ALTER TABLE banners
    ADD COLUMN banner_title_en VARCHAR(100) NULL AFTER banner_title,
    ADD COLUMN banner_subtitle_en VARCHAR(160) NULL AFTER banner_subtitle,
    ADD COLUMN banner_button_label_en VARCHAR(40) NULL AFTER banner_button_label;

UPDATE banners
SET banner_title_en = 'WHOLESALE PRICES',
    banner_subtitle_en = 'The more you buy, the more you save!',
    banner_button_label_en = 'Shop Now'
WHERE banner_title = 'BEI ZA JUMLA'
  AND banner_subtitle = 'Nunua zaidi, okoa zaidi.'
  AND banner_button_label = 'Nunua Sasa';