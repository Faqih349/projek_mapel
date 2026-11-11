CREATE DATABASE IF NOT EXISTS mutqinspace
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE mutqinspace;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(60) NOT NULL,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  initials VARCHAR(12) NOT NULL DEFAULT '',
  class_name VARCHAR(40) NOT NULL DEFAULT '',
  nisn VARCHAR(30) NOT NULL DEFAULT '',
  role ENUM('student', 'teacher') NOT NULL DEFAULT 'student',
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY users_username_unique (username),
  UNIQUE KEY users_email_unique (email),
  KEY users_role_idx (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS materials (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  position INT UNSIGNED NOT NULL,
  class_group_id BIGINT UNSIGNED NULL,
  title VARCHAR(150) NOT NULL,
  description VARCHAR(300) NOT NULL,
  course_title VARCHAR(120) NOT NULL,
  course_id VARCHAR(40) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  file_name VARCHAR(100) NULL,
  file_original_name VARCHAR(255) NULL,
  file_mime VARCHAR(100) NULL,
  file_size BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY materials_position_unique (position),
  KEY materials_course_idx (course_id, position),
  KEY materials_class_group_idx (class_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assignments (
  task_id VARCHAR(48) NOT NULL,
  course_id VARCHAR(40) NOT NULL,
  class_group_id BIGINT UNSIGNED NULL,
  course_title VARCHAR(120) NOT NULL,
  type ENUM('essay', 'quiz', 'essay_quiz') NOT NULL,
  exam_mode TINYINT(1) NOT NULL DEFAULT 0,
  icon VARCHAR(30) NOT NULL DEFAULT '',
  title VARCHAR(150) NOT NULL,
  due VARCHAR(80) NOT NULL,
  description VARCHAR(300) NOT NULL,
  prompt TEXT NOT NULL,
  questions JSON NOT NULL,
  file_name VARCHAR(100) NULL,
  file_original_name VARCHAR(255) NULL,
  file_mime VARCHAR(100) NULL,
  file_size BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (task_id),
  KEY assignments_course_idx (course_id),
  KEY assignments_class_group_idx (class_group_id),
  KEY assignments_type_idx (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  teacher_id BIGINT UNSIGNED NOT NULL,
  course_id VARCHAR(40) NOT NULL,
  class_name VARCHAR(100) NOT NULL,
  join_code CHAR(8) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY class_groups_join_code_unique (join_code),
  KEY class_groups_teacher_idx (teacher_id),
  KEY class_groups_course_idx (course_id),
  CONSTRAINT class_groups_teacher_fk FOREIGN KEY (teacher_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_memberships (
  class_group_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (class_group_id, student_id),
  KEY class_memberships_student_idx (student_id),
  CONSTRAINT class_memberships_group_fk FOREIGN KEY (class_group_id) REFERENCES class_groups (id) ON DELETE CASCADE,
  CONSTRAINT class_memberships_student_fk FOREIGN KEY (student_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS library_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uploader_id BIGINT UNSIGNED NOT NULL,
  category ENUM('textbook','juz_amma') NOT NULL,
  grade_level TINYINT UNSIGNED NULL,
  juz_number TINYINT UNSIGNED NULL,
  title VARCHAR(150) NOT NULL,
  file_name VARCHAR(100) NOT NULL,
  file_original_name VARCHAR(255) NOT NULL,
  file_mime VARCHAR(100) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY library_category_idx (category,grade_level,juz_number),
  CONSTRAINT library_uploader_fk FOREIGN KEY (uploader_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
