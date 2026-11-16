-- Review mode: a tester clicks any part of the screen and leaves a note; the owner later asks for all edits to be made
CREATE TABLE IF NOT EXISTS review_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  user_id INT NOT NULL,
  user_name VARCHAR(120) NULL,
  page_path VARCHAR(255) NOT NULL,
  selector VARCHAR(500) NULL,
  element_text VARCHAR(300) NULL,
  kind ENUM('comment','rename','change','bug') NOT NULL DEFAULT 'comment',
  new_text VARCHAR(500) NULL,
  note TEXT NULL,
  status ENUM('Open','Done','Rejected') NOT NULL DEFAULT 'Open',
  done_note VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL,
  KEY (company_id, status),
  KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
