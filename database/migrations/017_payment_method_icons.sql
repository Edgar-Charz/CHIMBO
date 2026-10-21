-- Payment method logos are optional; customer clients keep a built-in type icon when this is NULL.
ALTER TABLE payment_methods
    ADD COLUMN payment_method_icon_path VARCHAR(255) NULL AFTER payment_method_instructions;
