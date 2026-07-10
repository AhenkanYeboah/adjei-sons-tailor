-- =========================================================
-- SEED DATA — sample content with real stock photo URLs
-- Run this AFTER schema.sql
-- Images: Unsplash direct-CDN URLs (free to use, no attribution required
-- under the Unsplash License). Swap for your own product photography
-- once you have real garments/fabrics shot.
-- =========================================================

USE bespoke_tailor;

-- ---------------------------------------------------------
-- Garment types
-- ---------------------------------------------------------
INSERT INTO garment_types (name, base_price, base_production_days, is_active) VALUES
('Two-Piece Suit',        1800.00, 14, 1),
('Three-Piece Suit',      2200.00, 16, 1),
('Kaftan',                 950.00, 10, 1),
('Agbada (3-Piece)',      1600.00, 14, 1),
('Tailored Shirt',         320.00,  7, 1),
('Wedding Suit',          2600.00, 21, 1);

-- ---------------------------------------------------------
-- Fabrics (image_url = swatch/drape photo)
-- ---------------------------------------------------------
INSERT INTO fabrics (name, description, image_url, video_url, price_modifier, stock_status, free_swatch_eligible) VALUES
('Navy Italian Wool',        'Fine-twist Italian wool, year-round weight, subtle sheen.',        'https://images.unsplash.com/photo-1594938298603-c8148c4dae35?w=800&q=80', NULL, 350.00, 'in_stock', 1),
('Charcoal Herringbone',     'Classic herringbone weave, ideal for boardroom and formal wear.',   'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?w=800&q=80', NULL, 300.00, 'in_stock', 1),
('Kente-Inspired Jacquard',  'Woven jacquard with traditional Kente-pattern motifs.',            'https://images.unsplash.com/photo-1617196701537-7329482cc9fe?w=800&q=80', NULL, 480.00, 'low_stock', 1),
('Cream Linen',              'Breathable linen, perfect for weddings and warm-weather events.',   'https://images.unsplash.com/photo-1620799140408-edc6dcb6d633?w=800&q=80', NULL, 220.00, 'in_stock', 1),
('Black Super 120s Wool',    'Smooth, dense weave in deep black, formal-event staple.',           'https://images.unsplash.com/photo-1598522280649-e5e00e0a1c6c?w=800&q=80', NULL, 400.00, 'in_stock', 1),
('Ankara Wax Print',         'Bold, colourful wax-print cotton for statement kaftans and shirts.', 'https://images.unsplash.com/photo-1612459284970-e8f0f8be1c60?w=800&q=80', NULL, 180.00, 'in_stock', 1),
('Grey Windowpane Check',    'Light grey with a fine windowpane check overlay.',                  'https://images.unsplash.com/photo-1592878849122-facb97520f9b?w=800&q=80', NULL, 320.00, 'out_of_stock', 0),
('White Egyptian Cotton',    'Crisp long-staple cotton for tailored shirts.',                     'https://images.unsplash.com/photo-1596755094514-f87e34085b2c?w=800&q=80', NULL, 150.00, 'in_stock', 1);

-- ---------------------------------------------------------
-- Style options
-- ---------------------------------------------------------
INSERT INTO style_options (category, name, price_modifier, image_url, is_active) VALUES
('cut', 'Slim Fit',        0.00,  NULL, 1),
('cut', 'Classic Fit',     0.00,  NULL, 1),
('cut', 'Relaxed Fit',    50.00,  NULL, 1),
('lining', 'Standard Poly Lining', 0.00, NULL, 1),
('lining', 'Silk-Blend Lining',   120.00, NULL, 1),
('buttons', 'Horn Buttons',        0.00, NULL, 1),
('buttons', 'Mother-of-Pearl',    60.00, NULL, 1),
('collar', 'Notch Lapel',          0.00, NULL, 1),
('collar', 'Peak Lapel',          40.00, NULL, 1),
('collar', 'Mandarin Collar',     30.00, NULL, 1),
('cuff', 'Standard Cuff',          0.00, NULL, 1),
('cuff', 'Working Buttonholes',   45.00, NULL, 1),
('monogram', 'Monogram (up to 3 letters)', 35.00, NULL, 1);

-- ---------------------------------------------------------
-- Staff (password: "TailorDemo123!" — change before production)
-- Hash generated with PHP password_hash(), bcrypt cost 10
-- ---------------------------------------------------------
INSERT INTO staff (full_name, role, email, phone, whatsapp_number, password_hash, is_active) VALUES
('Kwame Asante',   'owner',    'kwame@kwamesons.gh',   '+233241000001', '+233241000001', '$2y$10$3z1qJgQm2y8W6QpF1x9ZseQnH4wS8Kk3q2XyZ0lJb8mQwR7fT9c5G', 1),
('Ama Boateng',    'tailor',   'ama@kwamesons.gh',      '+233241000002', '+233241000002', '$2y$10$3z1qJgQm2y8W6QpF1x9ZseQnH4wS8Kk3q2XyZ0lJb8mQwR7fT9c5G', 1),
('Yaw Mensah',     'measurer', 'yaw@kwamesons.gh',      '+233241000003', '+233241000003', '$2y$10$3z1qJgQm2y8W6QpF1x9ZseQnH4wS8Kk3q2XyZ0lJb8mQwR7fT9c5G', 1);

-- ---------------------------------------------------------
-- Sample client (password: "ClientDemo123!")
-- ---------------------------------------------------------
INSERT INTO clients (full_name, email, phone, password_hash, country, timezone, preferred_currency) VALUES
('Kojo Owusu', 'kojo.owusu@example.com', '+233201234567', '$2y$10$3z1qJgQm2y8W6QpF1x9ZseQnH4wS8Kk3q2XyZ0lJb8mQwR7fT9c5G', 'Ghana', 'Africa/Accra', 'GHS');

-- ---------------------------------------------------------
-- Gallery items (finished-look photos by occasion)
-- ---------------------------------------------------------
INSERT INTO gallery_items (image_url, occasion_category, client_story, client_name_display, is_featured) VALUES
('https://images.unsplash.com/photo-1507679799987-c73779587ccf?w=900&q=80', 'wedding',     'A three-piece cream linen suit for a beachside wedding in Ada.', 'Kojo O.', 1),
('https://images.unsplash.com/photo-1515372039744-b8f02a3ae446?w=900&q=80', 'corporate',   'Charcoal herringbone two-piece for a client''s new investment-banking role.', 'Adwoa K.', 1),
('https://images.unsplash.com/photo-1550246140-29f40b909e5a?w=900&q=80', 'graduation',   'A sharp navy suit for a University of Ghana graduation ceremony.', 'Nana A.', 0),
('https://images.unsplash.com/photo-1583744946564-b52ac1c389c8?w=900&q=80', 'traditional', 'Custom Agbada in Kente-inspired jacquard for a naming ceremony.', 'Kwabena T.', 1),
('https://images.unsplash.com/photo-1520975954732-35dd22299614?w=900&q=80', 'casual',      'Relaxed-fit Ankara shirt, made for a client''s birthday photoshoot.', NULL, 0),
('https://images.unsplash.com/photo-1552374196-c4e7ffc6e126?w=900&q=80', 'wedding',       'Groomsmen suits — five matching Super 120s wool two-pieces, one week turnaround.', 'Efo A.', 1);

-- ---------------------------------------------------------
-- Express slots for the current & example weeks
-- (Run separately per week in production via the admin panel;
--  these two rows just give the demo something to show.)
-- ---------------------------------------------------------
INSERT INTO express_slots (week_start_date, tier, slots_total, slots_used) VALUES
(CURDATE() - INTERVAL WEEKDAY(CURDATE()) DAY, 'express_5day', 6, 2),
(CURDATE() - INTERVAL WEEKDAY(CURDATE()) DAY, 'rush_48hr',    2, 1);
