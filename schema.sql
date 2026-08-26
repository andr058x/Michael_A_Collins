-- Schema per il catalogo libri di Micheal A. Collins
-- Importalo una sola volta dal pannello MySQL del tuo hosting (es. phpMyAdmin).

CREATE TABLE IF NOT EXISTS books (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  year VARCHAR(10) DEFAULT '',
  code VARCHAR(50) DEFAULT '',
  blurb TEXT,
  link VARCHAR(500) DEFAULT '#',
  cover VARCHAR(255) DEFAULT NULL,
  is_sample TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- I tre libri di esempio già presenti sul sito, così il catalogo non parte vuoto.
-- Puoi rimuoverli dal pannello autore in qualsiasi momento.
INSERT INTO books (title, year, code, blurb, link, cover, is_sample, sort_order) VALUES
('The Advantage Method', '2024', '338.04', 'A practical five-step system for turning a business idea into a lasting competitive edge.', '#', NULL, 1, 1),
('Thinking Like a System', '2023', '153.4', 'How to see connections where others see isolated problems, so you can make better decisions under pressure.', '#', NULL, 1, 2),
('The Founder''s Discipline', '2022', '658.11', 'The everyday, often invisible habits that separate ventures built to last from the ones that fizzle out.', '#', NULL, 1, 3);
