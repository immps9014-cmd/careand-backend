/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `permission_level` enum('super','branch','cs','analyst','developer') NOT NULL DEFAULT 'cs' COMMENT '권한 5단계',
  `department` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `admins_user_id_foreign` (`user_id`),
  CONSTRAINT `admins_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_inference_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_inference_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `model_id` bigint(20) unsigned NOT NULL,
  `input_summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `latency_ms` decimal(10,2) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 1,
  `error` text DEFAULT NULL,
  `inferred_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_inference_logs_model_id_inferred_at_index` (`model_id`,`inferred_at`),
  CONSTRAINT `ai_inference_logs_model_id_foreign` FOREIGN KEY (`model_id`) REFERENCES `ai_models` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_log_summaries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_log_summaries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `voice_log_id` bigint(20) unsigned DEFAULT NULL,
  `guardian_version` text NOT NULL COMMENT '보호자용 친근한 톤',
  `medical_version` text NOT NULL COMMENT '의료진용 정형 차트',
  `categorized` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT '식사/복약/운동/정서 등 분류',
  `confidence` decimal(4,3) NOT NULL,
  `llm_model` varchar(50) NOT NULL COMMENT 'claude-opus-4.7 등',
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `risk_score` decimal(4,3) DEFAULT NULL COMMENT '사실성 위험도 0~1',
  `verification` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '사실성 검증·안전 알림 결과',
  `guardian_original` longtext DEFAULT NULL COMMENT 'AI 가 만든 보호자용 원본(첫 수정 때 보관)',
  `medical_original` longtext DEFAULT NULL COMMENT 'AI 가 만든 의료진용 원본(첫 수정 때 보관)',
  `edited_by` bigint(20) unsigned DEFAULT NULL,
  `edited_role` varchar(12) DEFAULT NULL COMMENT 'caregiver|admin',
  `edit_reason` varchar(255) DEFAULT NULL,
  `edited_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_log_summaries_voice_log_id_foreign` (`voice_log_id`),
  KEY `ai_log_summaries_session_id_index` (`session_id`),
  KEY `ai_log_summaries_edited_by_foreign` (`edited_by`),
  CONSTRAINT `ai_log_summaries_edited_by_foreign` FOREIGN KEY (`edited_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ai_log_summaries_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `care_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_log_summaries_voice_log_id_foreign` FOREIGN KEY (`voice_log_id`) REFERENCES `voice_logs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_models`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_models` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `model_name` varchar(50) NOT NULL COMMENT 'matching, stt, llm, anomaly, forecast',
  `version` varchar(30) NOT NULL,
  `status` enum('active','shadow','deprecated') NOT NULL DEFAULT 'shadow',
  `accuracy` decimal(5,4) DEFAULT NULL,
  `avg_latency_ms` decimal(10,2) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'endpoint, params 등',
  `audited_at` timestamp NULL DEFAULT NULL,
  `bias_report` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '편향성 감사 결과',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ai_models_model_name_version_unique` (`model_name`,`version`),
  KEY `ai_models_model_name_status_index` (`model_name`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_recommendations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_recommendations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `senior_id` bigint(20) unsigned NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  `recommendation_type` enum('matching','health_intervention','content','schedule') NOT NULL,
  `input_summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `confidence` decimal(4,3) NOT NULL,
  `was_adopted` tinyint(1) DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_recommendations_model_id_foreign` (`model_id`),
  KEY `ai_recommendations_senior_id_recommendation_type_index` (`senior_id`,`recommendation_type`),
  CONSTRAINT `ai_recommendations_model_id_foreign` FOREIGN KEY (`model_id`) REFERENCES `ai_models` (`id`),
  CONSTRAINT `ai_recommendations_senior_id_foreign` FOREIGN KEY (`senior_id`) REFERENCES `seniors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `anomaly_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `anomaly_alerts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `senior_id` bigint(20) unsigned NOT NULL,
  `risk_type` enum('fall','delirium','depression','nutrition','other') NOT NULL,
  `risk_score` decimal(5,2) NOT NULL COMMENT '0~100점',
  `severity` enum('low','mid','high','critical') NOT NULL,
  `trigger_pattern` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT '탐지 근거 (Explainable)',
  `recommendation` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '대응 가이드',
  `status` enum('new','acknowledged','in_progress','resolved','dismissed') NOT NULL DEFAULT 'new',
  `resolution_note` text DEFAULT NULL,
  `resolved_by` bigint(20) unsigned DEFAULT NULL,
  `detected_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `anomaly_alerts_resolved_by_foreign` (`resolved_by`),
  KEY `anomaly_alerts_senior_id_severity_status_index` (`senior_id`,`severity`,`status`),
  KEY `anomaly_alerts_detected_at_index` (`detected_at`),
  CONSTRAINT `anomaly_alerts_resolved_by_foreign` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `anomaly_alerts_senior_id_foreign` FOREIGN KEY (`senior_id`) REFERENCES `seniors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `assessment_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `assessment_submissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `assessment_id` bigint(20) unsigned NOT NULL,
  `enrollment_id` bigint(20) unsigned NOT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `score` decimal(5,2) DEFAULT NULL,
  `auto_graded` tinyint(1) NOT NULL DEFAULT 0,
  `answers_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `submission_video_url` varchar(500) DEFAULT NULL,
  `submission_text` text DEFAULT NULL,
  `graded_by` bigint(20) unsigned DEFAULT NULL,
  `graded_at` timestamp NULL DEFAULT NULL,
  `grader_comment` text DEFAULT NULL,
  `rubric_scores` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `assessment_submissions_assessment_id_enrollment_id_unique` (`assessment_id`,`enrollment_id`),
  KEY `assessment_submissions_enrollment_id_index` (`enrollment_id`),
  CONSTRAINT `assessment_submissions_assessment_id_foreign` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assessment_submissions_enrollment_id_foreign` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `assessments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `assessments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `course_session_id` bigint(20) unsigned NOT NULL,
  `course_lesson_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `assessment_type` enum('quiz','midterm','final','practical_video','assignment') NOT NULL,
  `max_score` decimal(5,2) NOT NULL DEFAULT 100.00,
  `passing_score` decimal(5,2) DEFAULT NULL,
  `weight` decimal(5,2) NOT NULL DEFAULT 1.00,
  `released_at` timestamp NULL DEFAULT NULL,
  `deadline_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `assessments_course_session_id_index` (`course_session_id`),
  KEY `assessments_course_lesson_id_index` (`course_lesson_id`),
  KEY `assessments_assessment_type_index` (`assessment_type`),
  CONSTRAINT `assessments_course_lesson_id_foreign` FOREIGN KEY (`course_lesson_id`) REFERENCES `course_lessons` (`id`),
  CONSTRAINT `assessments_course_session_id_foreign` FOREIGN KEY (`course_session_id`) REFERENCES `course_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `attendance_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `event_type` enum('checkin','checkout') NOT NULL,
  `lat` decimal(10,7) NOT NULL,
  `lng` decimal(10,7) NOT NULL,
  `distance_m` decimal(8,2) NOT NULL COMMENT '자택과의 거리(m)',
  `accuracy_m` decimal(6,2) DEFAULT NULL,
  `is_valid` tinyint(1) NOT NULL DEFAULT 1 COMMENT '200m 이내 여부',
  `logged_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `out_of_range` tinyint(1) NOT NULL DEFAULT 0 COMMENT '서비스 장소 반경 밖(허용 한도 안) — 운영팀 경고 대상',
  `reviewed_at` timestamp NULL DEFAULT NULL COMMENT '운영팀이 반경 밖 기록을 확인한 시각',
  PRIMARY KEY (`id`),
  KEY `attendance_logs_session_id_event_type_index` (`session_id`,`event_type`),
  CONSTRAINT `attendance_logs_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `care_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `logged_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `reason` varchar(300) DEFAULT NULL COMMENT '접근 사유(WHY)',
  `prev_hash` char(64) DEFAULT NULL COMMENT '직전 행 해시',
  `hash` char(64) DEFAULT NULL COMMENT '해시 체인',
  PRIMARY KEY (`id`),
  KEY `audit_logs_actor_id_logged_at_index` (`actor_id`,`logged_at`),
  KEY `audit_logs_entity_type_entity_id_index` (`entity_type`,`entity_id`),
  CONSTRAINT `audit_logs_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL COMMENT '지점 코드 (HS/OS/SW/PT)',
  `name` varchar(100) NOT NULL COMMENT '지점명',
  `type` enum('direct','franchise') NOT NULL DEFAULT 'direct' COMMENT '직영/가맹',
  `parent_branch_id` bigint(20) unsigned DEFAULT NULL COMMENT '본부 (가맹의 경우)',
  `address` varchar(500) NOT NULL,
  `address_detail` varchar(200) DEFAULT NULL,
  `region_code` varchar(20) NOT NULL COMMENT '시군구 코드',
  `phone` varchar(20) DEFAULT NULL,
  `manager_user_id` bigint(20) unsigned DEFAULT NULL COMMENT '지점장',
  `business_number` varchar(20) DEFAULT NULL,
  `opened_at` date NOT NULL,
  `closed_at` date DEFAULT NULL,
  `status` enum('active','paused','closed') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `branches_code_unique` (`code`),
  KEY `branches_region_code_index` (`region_code`),
  KEY `branches_type_status_index` (`type`,`status`),
  KEY `branches_parent_branch_id_foreign` (`parent_branch_id`),
  CONSTRAINT `branches_parent_branch_id_foreign` FOREIGN KEY (`parent_branch_id`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `care_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `care_activities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `category` enum('meal','medication','exercise','bath','mood','cognition','other','cleaning','repair','organizing','nursing_care','position_change') NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT '카테고리별 데이터: meal_pct, medication_taken 등',
  `memo` text DEFAULT NULL,
  `performed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `care_activities_session_id_category_index` (`session_id`,`category`),
  CONSTRAINT `care_activities_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `care_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `care_log_shares`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `care_log_shares` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `revoked_at` timestamp NULL DEFAULT NULL,
  `view_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_viewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `care_log_shares_token_hash_unique` (`token_hash`),
  KEY `care_log_shares_session_id_foreign` (`session_id`),
  KEY `care_log_shares_created_by_foreign` (`created_by`),
  CONSTRAINT `care_log_shares_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `care_log_shares_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `care_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `care_photos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `care_photos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `photo_url` varchar(500) NOT NULL,
  `thumbnail_url` varchar(500) DEFAULT NULL,
  `caption` varchar(200) DEFAULT NULL,
  `is_curated` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'AI가 선별한 베스트 사진',
  `taken_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `care_photos_session_id_index` (`session_id`),
  CONSTRAINT `care_photos_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `care_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `care_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `care_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `match_id` bigint(20) unsigned NOT NULL,
  `scheduled_start` timestamp NULL DEFAULT NULL COMMENT '세션 예정 시작(다세션용, NULL=match 일정)',
  `scheduled_end` timestamp NULL DEFAULT NULL,
  `actual_start` timestamp NULL DEFAULT NULL,
  `actual_end` timestamp NULL DEFAULT NULL,
  `duration_min` int(10) unsigned DEFAULT NULL,
  `status` enum('scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  `review_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending' COMMENT 'AI 일지 검수 상태',
  `review_note` text DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `cancel_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `log_started_at` timestamp NULL DEFAULT NULL COMMENT 'KPI: 일지 작성 시작',
  `log_sent_at` timestamp NULL DEFAULT NULL COMMENT 'KPI: 보호자 전송 완료',
  `reminder_sent_at` timestamp NULL DEFAULT NULL COMMENT '방문 전 리마인더 보낸 시각',
  `journal_chips` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '돌봄전문가가 누른 칩 코드 목록',
  `journal_note` text DEFAULT NULL COMMENT '칩과 함께 적은 메모 — MedicalCrypto 암호화',
  `chips_updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `care_sessions_match_id_foreign` (`match_id`),
  KEY `care_sessions_status_index` (`status`),
  KEY `care_sessions_actual_start_index` (`actual_start`),
  KEY `care_sessions_scheduled_start_index` (`scheduled_start`),
  CONSTRAINT `care_sessions_match_id_foreign` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `career_milestones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `career_milestones` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `caregiver_id` bigint(20) unsigned NOT NULL,
  `milestone_type` enum('track_promoted','track_demoted','cert_acquired','mentor_qualified','instructor_recommended','first_match','100_sessions','300_sessions','500_sessions') NOT NULL,
  `from_track` varchar(50) DEFAULT NULL,
  `to_track` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `occurred_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `career_milestones_caregiver_id_occurred_at_index` (`caregiver_id`,`occurred_at`),
  KEY `career_milestones_milestone_type_index` (`milestone_type`),
  CONSTRAINT `career_milestones_caregiver_id_foreign` FOREIGN KEY (`caregiver_id`) REFERENCES `caregivers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `caregiver_blocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caregiver_blocks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `caregiver_id` bigint(20) unsigned NOT NULL,
  `target_type` varchar(16) NOT NULL,
  `target_id` bigint(20) unsigned NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `caregiver_block_unique` (`caregiver_id`,`target_type`,`target_id`),
  KEY `caregiver_blocks_target_type_target_id_index` (`target_type`,`target_id`),
  CONSTRAINT `caregiver_blocks_caregiver_id_foreign` FOREIGN KEY (`caregiver_id`) REFERENCES `caregivers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `caregiver_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caregiver_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `caregiver_id` bigint(20) unsigned NOT NULL,
  `doc_type` varchar(30) NOT NULL COMMENT 'config/caregiver_docs.php types 키',
  `file_path` varchar(255) NOT NULL COMMENT 'local 디스크 상대경로(암호화 파일)',
  `original_name` varchar(190) DEFAULT NULL,
  `mime` varchar(100) DEFAULT NULL,
  `size_bytes` int(10) unsigned NOT NULL DEFAULT 0,
  `sha256` char(64) NOT NULL COMMENT '원본 파일 해시(위변조 확인)',
  `status` varchar(12) NOT NULL DEFAULT 'submitted' COMMENT 'submitted|verified|rejected|replaced',
  `issued_at` date DEFAULT NULL COMMENT '서류 발급일(범죄경력·건강진단 유효기간 계산)',
  `expires_at` date DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `caregiver_documents_reviewed_by_foreign` (`reviewed_by`),
  KEY `caregiver_documents_caregiver_id_doc_type_status_index` (`caregiver_id`,`doc_type`,`status`),
  CONSTRAINT `caregiver_documents_caregiver_id_foreign` FOREIGN KEY (`caregiver_id`) REFERENCES `caregivers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `caregiver_documents_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `caregiver_favorites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caregiver_favorites` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `caregiver_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `caregiver_favorites_user_id_caregiver_id_unique` (`user_id`,`caregiver_id`),
  KEY `caregiver_favorites_caregiver_id_foreign` (`caregiver_id`),
  CONSTRAINT `caregiver_favorites_caregiver_id_foreign` FOREIGN KEY (`caregiver_id`) REFERENCES `caregivers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `caregiver_favorites_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `caregiver_invites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caregiver_invites` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `org_id` bigint(20) unsigned NOT NULL,
  `phone` varchar(20) NOT NULL,
  `token` varchar(64) NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `invited_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `accepted_caregiver_id` bigint(20) unsigned DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `caregiver_invites_token_unique` (`token`),
  KEY `caregiver_invites_invited_by_user_id_foreign` (`invited_by_user_id`),
  KEY `caregiver_invites_accepted_caregiver_id_foreign` (`accepted_caregiver_id`),
  KEY `caregiver_invites_org_id_status_index` (`org_id`,`status`),
  KEY `caregiver_invites_phone_index` (`phone`),
  KEY `caregiver_invites_status_index` (`status`),
  CONSTRAINT `caregiver_invites_accepted_caregiver_id_foreign` FOREIGN KEY (`accepted_caregiver_id`) REFERENCES `caregivers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `caregiver_invites_invited_by_user_id_foreign` FOREIGN KEY (`invited_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `caregiver_invites_org_id_foreign` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `caregiver_resumes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caregiver_resumes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `caregiver_id` bigint(20) unsigned NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `is_current` tinyint(1) NOT NULL DEFAULT 1,
  `headline` varchar(200) DEFAULT NULL,
  `self_introduction` text DEFAULT NULL,
  `strengths` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `weaknesses_for_improvement` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `experience_summary` text DEFAULT NULL,
  `senior_card_content` text DEFAULT NULL,
  `postpartum_card_content` text DEFAULT NULL,
  `generated_by_model` varchar(50) DEFAULT NULL,
  `generated_at` timestamp NULL DEFAULT NULL,
  `pdf_url` varchar(500) DEFAULT NULL,
  `reviewed_by_caregiver` tinyint(1) NOT NULL DEFAULT 0,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `caregiver_comments` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `caregiver_resumes_caregiver_id_is_current_index` (`caregiver_id`,`is_current`),
  CONSTRAINT `caregiver_resumes_caregiver_id_foreign` FOREIGN KEY (`caregiver_id`) REFERENCES `caregivers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `caregivers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caregivers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `org_id` bigint(20) unsigned DEFAULT NULL,
  `birth_date` date NOT NULL,
  `gender` enum('M','F') NOT NULL,
  `license_no` varchar(30) DEFAULT NULL COMMENT '요양보호사 자격번호(가사 인력은 선택)',
  `license_type` varchar(40) DEFAULT NULL,
  `license_image_url` varchar(500) DEFAULT NULL,
  `license_issued_at` date DEFAULT NULL,
  `license_verified_at` timestamp NULL DEFAULT NULL COMMENT '보건복지부 진위확인 시각',
  `specialties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '특기: 치매, 당뇨, 뇌졸중 등',
  `base_address` varchar(255) NOT NULL,
  `base_lat` decimal(10,7) DEFAULT NULL,
  `base_lng` decimal(10,7) DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL COMMENT '소속 지점',
  `service_domains` set('senior','postpartum','nursing','housekeeping','living_support','childcare','mental_care') NOT NULL DEFAULT 'senior' COMMENT '활동 도메인 SET',
  `career_track` enum('rookie','settled','excellent','premium','instructor') NOT NULL DEFAULT 'rookie' COMMENT '커리어 트랙 5단계',
  `mentor_caregiver_id` bigint(20) unsigned DEFAULT NULL COMMENT '담당 멘토 인력',
  `can_be_mentor` tinyint(1) NOT NULL DEFAULT 0 COMMENT '멘토 자격 보유',
  `rating_avg` decimal(3,2) NOT NULL DEFAULT 0.00 COMMENT '평점 평균',
  `rating_count` int(10) unsigned NOT NULL DEFAULT 0,
  `completed_sessions` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '완료 세션 수',
  `grade_level` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1~5 등급 (플랫폼 자체)',
  `default_rate` decimal(10,2) DEFAULT NULL COMMENT '표준 희망 시급(입찰 프리필/자동입찰)',
  `auto_bid` tinyint(1) NOT NULL DEFAULT 0 COMMENT '초대 시 default_rate로 자동 입찰',
  `status` enum('pending','active','suspended','leave','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `bank_name` varchar(40) DEFAULT NULL COMMENT '정산 은행',
  `bank_account` text DEFAULT NULL COMMENT '정산 계좌번호 — MedicalCrypto 암호화',
  `bank_holder` varchar(40) DEFAULT NULL COMMENT '예금주',
  `bank_updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `caregivers_user_id_foreign` (`user_id`),
  KEY `caregivers_org_id_foreign` (`org_id`),
  KEY `caregivers_status_index` (`status`),
  KEY `caregivers_rating_avg_index` (`rating_avg`),
  KEY `caregivers_base_lat_base_lng_index` (`base_lat`,`base_lng`),
  KEY `idx_caregivers_branch` (`branch_id`),
  KEY `idx_caregivers_service_domains` (`service_domains`),
  KEY `idx_caregivers_career_track` (`career_track`),
  KEY `fk_caregivers_mentor` (`mentor_caregiver_id`),
  CONSTRAINT `caregivers_org_id_foreign` FOREIGN KEY (`org_id`) REFERENCES `organizations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `caregivers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_caregivers_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_caregivers_mentor` FOREIGN KEY (`mentor_caregiver_id`) REFERENCES `caregivers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `certifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `certifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `enrollment_id` bigint(20) unsigned DEFAULT NULL,
  `cert_name` varchar(200) NOT NULL,
  `cert_type` enum('national','private','completion') NOT NULL,
  `cert_issuer` varchar(200) NOT NULL,
  `cert_number` varchar(100) DEFAULT NULL,
  `issued_date` date NOT NULL,
  `expires_date` date DEFAULT NULL,
  `cert_image_url` varchar(500) DEFAULT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `certifications_user_id_index` (`user_id`),
  KEY `certifications_enrollment_id_index` (`enrollment_id`),
  KEY `certifications_expires_date_index` (`expires_date`),
  CONSTRAINT `certifications_enrollment_id_foreign` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`),
  CONSTRAINT `certifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chatbot_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chatbot_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `role` enum('user','assistant') NOT NULL,
  `content` text NOT NULL,
  `sources` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'RAG 출처',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chatbot_messages_session_id_index` (`session_id`),
  CONSTRAINT `chatbot_messages_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `chatbot_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chatbot_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chatbot_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `guardian_id` bigint(20) unsigned NOT NULL,
  `topic` varchar(100) DEFAULT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ended_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chatbot_sessions_guardian_id_index` (`guardian_id`),
  CONSTRAINT `chatbot_sessions_guardian_id_foreign` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `children`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `children` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `guardian_id` bigint(20) unsigned NOT NULL,
  `name` varchar(50) NOT NULL,
  `birth_date` date NOT NULL,
  `gender` enum('M','F') NOT NULL,
  `home_address` varchar(255) NOT NULL,
  `home_lat` decimal(10,7) DEFAULT NULL,
  `home_lng` decimal(10,7) DEFAULT NULL,
  `special_notes` text DEFAULT NULL COMMENT '특이사항(암호화)',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `children_guardian_id_foreign` (`guardian_id`),
  KEY `children_home_lat_index` (`home_lat`),
  CONSTRAINT `children_guardian_id_foreign` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `class_attendance_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `class_attendance_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `enrollment_id` bigint(20) unsigned NOT NULL,
  `course_lesson_id` bigint(20) unsigned DEFAULT NULL,
  `attendance_date` date NOT NULL,
  `attendance_status` enum('present','late','left_early','absent','excused') NOT NULL,
  `check_in_at` timestamp NULL DEFAULT NULL,
  `check_in_method` enum('qr','manual','biometric') DEFAULT NULL,
  `check_in_lat` decimal(10,7) DEFAULT NULL,
  `check_in_lng` decimal(10,7) DEFAULT NULL,
  `check_in_distance_m` int(11) DEFAULT NULL,
  `check_in_selfie_url` varchar(500) DEFAULT NULL,
  `face_match_score` decimal(5,4) DEFAULT NULL,
  `check_out_at` timestamp NULL DEFAULT NULL,
  `check_out_method` enum('qr','manual','biometric') DEFAULT NULL,
  `excuse_reason` text DEFAULT NULL,
  `excuse_approved_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_class_enroll_date` (`enrollment_id`,`attendance_date`),
  KEY `class_attendance_logs_attendance_status_index` (`attendance_status`),
  KEY `class_attendance_logs_course_lesson_id_index` (`course_lesson_id`),
  CONSTRAINT `fk_class_att_enroll` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_class_att_lesson` FOREIGN KEY (`course_lesson_id`) REFERENCES `course_lessons` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `course_lessons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `course_lessons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `course_id` bigint(20) unsigned NOT NULL,
  `sequence` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `duration_min` int(11) NOT NULL,
  `lesson_type` enum('lecture','practice','exam','discussion') NOT NULL,
  `is_mandatory` tinyint(1) NOT NULL DEFAULT 1,
  `video_url` varchar(500) DEFAULT NULL,
  `materials_url` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `course_lessons_course_id_sequence_unique` (`course_id`,`sequence`),
  CONSTRAINT `course_lessons_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `course_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `course_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `course_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `instructor_id` bigint(20) unsigned DEFAULT NULL,
  `cohort_name` varchar(50) NOT NULL COMMENT '예: 2026-1기',
  `capacity` int(11) NOT NULL DEFAULT 30,
  `enrolled_count` int(11) NOT NULL DEFAULT 0,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `classroom_address` varchar(500) DEFAULT NULL,
  `classroom_lat` decimal(10,7) DEFAULT NULL COMMENT 'GPS 검증',
  `classroom_lng` decimal(10,7) DEFAULT NULL,
  `classroom_radius_m` int(11) NOT NULL DEFAULT 50,
  `schedule_pattern` varchar(200) DEFAULT NULL,
  `status` enum('planned','recruiting','ongoing','completed','cancelled') NOT NULL DEFAULT 'planned',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `course_sessions_course_id_index` (`course_id`),
  KEY `course_sessions_branch_id_index` (`branch_id`),
  KEY `course_sessions_instructor_id_index` (`instructor_id`),
  KEY `course_sessions_start_date_end_date_index` (`start_date`,`end_date`),
  KEY `course_sessions_status_index` (`status`),
  CONSTRAINT `course_sessions_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `course_sessions_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`),
  CONSTRAINT `course_sessions_instructor_id_foreign` FOREIGN KEY (`instructor_id`) REFERENCES `instructors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `courses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(200) NOT NULL,
  `category` enum('mother_newborn','postpartum_grade1','housekeeping','caregiver_grade1','mentor','other') NOT NULL,
  `description` text DEFAULT NULL,
  `total_hours` int(11) NOT NULL,
  `passing_attendance_rate` decimal(5,2) NOT NULL DEFAULT 80.00,
  `issuing_certification` varchar(200) DEFAULT NULL,
  `related_govt_qualification` varchar(200) DEFAULT NULL,
  `is_govt_supported` tinyint(1) NOT NULL DEFAULT 0,
  `govt_support_program` varchar(100) DEFAULT NULL,
  `tuition_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `courses_code_unique` (`code`),
  KEY `courses_category_index` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `enrollments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `course_session_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `govt_support_type` enum('none','tomorrow_card','national_tomorrow_card','employment_promotion') NOT NULL DEFAULT 'none',
  `govt_support_application_id` varchar(100) DEFAULT NULL COMMENT 'HRD-Net 신청번호',
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('pending','active','completed','dropped','cancelled') NOT NULL DEFAULT 'pending',
  `attendance_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `progress_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `final_score` decimal(5,2) DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `auto_registered_caregiver_id` bigint(20) unsigned DEFAULT NULL COMMENT '수료 후 자동 생성된 caregiver_id',
  `auto_registered_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `enrollments_course_session_id_user_id_unique` (`course_session_id`,`user_id`),
  KEY `enrollments_user_id_index` (`user_id`),
  KEY `enrollments_status_index` (`status`),
  KEY `enrollments_auto_registered_caregiver_id_index` (`auto_registered_caregiver_id`),
  CONSTRAINT `enrollments_auto_registered_caregiver_id_foreign` FOREIGN KEY (`auto_registered_caregiver_id`) REFERENCES `caregivers` (`id`),
  CONSTRAINT `enrollments_course_session_id_foreign` FOREIGN KEY (`course_session_id`) REFERENCES `course_sessions` (`id`),
  CONSTRAINT `enrollments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `epds_assessments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `epds_assessments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `postpartum_client_id` bigint(20) unsigned NOT NULL,
  `assessment_date` date NOT NULL,
  `q1_score` int(11) NOT NULL COMMENT 'Q1 점수 (0-3)',
  `q2_score` int(11) NOT NULL COMMENT 'Q2 점수 (0-3)',
  `q3_score` int(11) NOT NULL COMMENT 'Q3 점수 (0-3)',
  `q4_score` int(11) NOT NULL COMMENT 'Q4 점수 (0-3)',
  `q5_score` int(11) NOT NULL COMMENT 'Q5 점수 (0-3)',
  `q6_score` int(11) NOT NULL COMMENT 'Q6 점수 (0-3)',
  `q7_score` int(11) NOT NULL COMMENT 'Q7 점수 (0-3)',
  `q8_score` int(11) NOT NULL COMMENT 'Q8 점수 (0-3)',
  `q9_score` int(11) NOT NULL COMMENT 'Q9 점수 (0-3)',
  `q10_score` int(11) NOT NULL COMMENT '자해 사고 — 1점 이상이면 즉시 알림',
  `total_score` int(11) NOT NULL COMMENT '총점 (0~30)',
  `risk_level` enum('low','medium','high','critical') NOT NULL,
  `llm_sentiment_score` decimal(5,4) DEFAULT NULL,
  `combined_risk_score` decimal(5,4) DEFAULT NULL,
  `action_taken` enum('none','rematch','counseling','medical_referral') DEFAULT NULL,
  `action_taken_at` timestamp NULL DEFAULT NULL,
  `action_taken_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_epds_client_date` (`postpartum_client_id`,`assessment_date`),
  KEY `epds_assessments_risk_level_index` (`risk_level`),
  KEY `epds_assessments_total_score_index` (`total_score`),
  CONSTRAINT `epds_assessments_postpartum_client_id_foreign` FOREIGN KEY (`postpartum_client_id`) REFERENCES `postpartum_clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `franchise_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `franchise_applications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `applicant_name` varchar(50) NOT NULL,
  `applicant_phone_encrypted` blob NOT NULL,
  `applicant_email` varchar(200) DEFAULT NULL,
  `desired_region` varchar(200) DEFAULT NULL,
  `investment_capacity` decimal(12,2) DEFAULT NULL,
  `business_experience` text DEFAULT NULL,
  `motivation` text DEFAULT NULL,
  `referral_source` varchar(100) DEFAULT NULL,
  `status` enum('submitted','reviewing','interview_scheduled','approved','rejected','cancelled') NOT NULL DEFAULT 'submitted',
  `reviewer_user_id` bigint(20) unsigned DEFAULT NULL,
  `review_score` int(11) DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `interview_at` timestamp NULL DEFAULT NULL,
  `converted_contract_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `franchise_applications_status_index` (`status`),
  KEY `franchise_applications_reviewer_user_id_index` (`reviewer_user_id`),
  KEY `franchise_applications_converted_contract_id_foreign` (`converted_contract_id`),
  CONSTRAINT `franchise_applications_converted_contract_id_foreign` FOREIGN KEY (`converted_contract_id`) REFERENCES `franchise_contracts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `franchise_contracts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `franchise_contracts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint(20) unsigned NOT NULL,
  `franchisee_user_id` bigint(20) unsigned NOT NULL,
  `contract_number` varchar(50) NOT NULL,
  `contract_start_date` date NOT NULL,
  `contract_end_date` date NOT NULL,
  `initial_franchise_fee` decimal(12,2) NOT NULL COMMENT '가맹비',
  `monthly_royalty_rate` decimal(5,4) NOT NULL DEFAULT 0.0500 COMMENT '월매출 대비 비율',
  `monthly_marketing_fee` decimal(10,2) DEFAULT NULL,
  `minimum_monthly_royalty` decimal(10,2) DEFAULT NULL,
  `exclusive_region_codes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `status` enum('draft','active','paused','terminated','expired') NOT NULL DEFAULT 'draft',
  `terminated_at` timestamp NULL DEFAULT NULL,
  `termination_reason` text DEFAULT NULL,
  `contract_pdf_url` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `franchise_contracts_branch_id_unique` (`branch_id`),
  UNIQUE KEY `franchise_contracts_contract_number_unique` (`contract_number`),
  KEY `franchise_contracts_franchisee_user_id_index` (`franchisee_user_id`),
  KEY `franchise_contracts_status_index` (`status`),
  CONSTRAINT `franchise_contracts_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `franchise_contracts_franchisee_user_id_foreign` FOREIGN KEY (`franchisee_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `franchise_data_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `franchise_data_policies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `franchise_contract_id` bigint(20) unsigned NOT NULL,
  `can_view_caregiver_full_profile` tinyint(1) NOT NULL DEFAULT 0,
  `can_view_client_pii` tinyint(1) NOT NULL DEFAULT 0,
  `can_view_other_branch_data` tinyint(1) NOT NULL DEFAULT 0,
  `can_export_data` tinyint(1) NOT NULL DEFAULT 0,
  `can_use_ai_features` tinyint(1) NOT NULL DEFAULT 1,
  `daily_api_call_limit` int(11) NOT NULL DEFAULT 10000,
  `monthly_data_export_mb` int(11) NOT NULL DEFAULT 100,
  `effective_from` date NOT NULL,
  `effective_until` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `franchise_data_policies_franchise_contract_id_index` (`franchise_contract_id`),
  CONSTRAINT `franchise_data_policies_franchise_contract_id_foreign` FOREIGN KEY (`franchise_contract_id`) REFERENCES `franchise_contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `franchise_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `franchise_settlements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `franchise_contract_id` bigint(20) unsigned NOT NULL,
  `period_year` int(11) NOT NULL,
  `period_month` int(11) NOT NULL,
  `gross_revenue` decimal(12,2) NOT NULL DEFAULT 0.00,
  `senior_revenue` decimal(12,2) NOT NULL DEFAULT 0.00,
  `postpartum_revenue` decimal(12,2) NOT NULL DEFAULT 0.00,
  `education_revenue` decimal(12,2) NOT NULL DEFAULT 0.00,
  `royalty_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `marketing_fee_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `other_charges` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_payable_to_hq` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('pending','paid','overdue','disputed') NOT NULL DEFAULT 'pending',
  `paid_at` timestamp NULL DEFAULT NULL,
  `paid_amount` decimal(12,2) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `invoice_url` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_settle_contract_period` (`franchise_contract_id`,`period_year`,`period_month`),
  KEY `franchise_settlements_payment_status_index` (`payment_status`),
  CONSTRAINT `franchise_settlements_franchise_contract_id_foreign` FOREIGN KEY (`franchise_contract_id`) REFERENCES `franchise_contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `govt_education_supports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `govt_education_supports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `enrollment_id` bigint(20) unsigned NOT NULL,
  `support_type` enum('tomorrow_card','national_tomorrow_card','employment_promotion','other') NOT NULL,
  `application_number` varchar(100) DEFAULT NULL COMMENT 'HRD-Net 신청번호',
  `application_date` date DEFAULT NULL,
  `approval_status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `approved_amount` decimal(10,2) DEFAULT NULL,
  `self_pay_amount` decimal(10,2) DEFAULT NULL,
  `hrd_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `govt_education_supports_enrollment_id_unique` (`enrollment_id`),
  KEY `govt_education_supports_approval_status_index` (`approval_status`),
  CONSTRAINT `govt_education_supports_enrollment_id_foreign` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `guardians`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `guardians` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `relation` varchar(20) DEFAULT NULL,
  `intent` varchar(20) NOT NULL DEFAULT 'care' COMMENT 'care=보호자, housekeeping=가사요청자',
  `contact_address` varchar(255) DEFAULT NULL,
  `preferences` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '선호 인력 조건',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `guardians_user_id_foreign` (`user_id`),
  KEY `guardians_intent_index` (`intent`),
  CONSTRAINT `guardians_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `health_timeseries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `health_timeseries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `senior_id` bigint(20) unsigned NOT NULL,
  `metric_name` varchar(50) NOT NULL COMMENT 'meal_pct, sleep_hours, mood_score 등',
  `value` decimal(10,4) NOT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `health_timeseries_senior_id_metric_name_recorded_at_index` (`senior_id`,`metric_name`,`recorded_at`),
  CONSTRAINT `health_timeseries_senior_id_foreign` FOREIGN KEY (`senior_id`) REFERENCES `seniors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `instructor_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `instructor_reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `enrollment_id` bigint(20) unsigned NOT NULL,
  `instructor_id` bigint(20) unsigned NOT NULL,
  `rating` int(11) NOT NULL COMMENT '1-5',
  `review` text DEFAULT NULL,
  `is_anonymous` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `instructor_reviews_enrollment_id_instructor_id_unique` (`enrollment_id`,`instructor_id`),
  KEY `instructor_reviews_instructor_id_index` (`instructor_id`),
  CONSTRAINT `instructor_reviews_enrollment_id_foreign` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `instructor_reviews_instructor_id_foreign` FOREIGN KEY (`instructor_id`) REFERENCES `instructors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `instructors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `instructors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `promoted_from_caregiver_id` bigint(20) unsigned DEFAULT NULL COMMENT '인력 → 강사 환류',
  `bio` text DEFAULT NULL,
  `qualifications` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `teaching_categories` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `rating_avg` decimal(3,2) NOT NULL DEFAULT 0.00,
  `total_students` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','paused','retired') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `instructors_user_id_unique` (`user_id`),
  KEY `instructors_status_index` (`status`),
  KEY `instructors_promoted_from_caregiver_id_foreign` (`promoted_from_caregiver_id`),
  CONSTRAINT `instructors_promoted_from_caregiver_id_foreign` FOREIGN KEY (`promoted_from_caregiver_id`) REFERENCES `caregivers` (`id`),
  CONSTRAINT `instructors_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ltc_vouchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ltc_vouchers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `senior_id` bigint(20) unsigned NOT NULL,
  `period_month` date NOT NULL COMMENT 'YYYY-MM-01 형식',
  `monthly_limit` decimal(10,2) NOT NULL COMMENT '월 한도',
  `used_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `remaining_amount` decimal(10,2) NOT NULL,
  `copay_rate` tinyint(4) NOT NULL COMMENT '본인부담률 % (15, 9, 6 등)',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ltc_vouchers_senior_id_period_month_unique` (`senior_id`,`period_month`),
  CONSTRAINT `ltc_vouchers_senior_id_foreign` FOREIGN KEY (`senior_id`) REFERENCES `seniors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `match_candidates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `match_candidates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` bigint(20) unsigned NOT NULL,
  `caregiver_id` bigint(20) unsigned NOT NULL,
  `source` varchar(8) NOT NULL DEFAULT 'ai',
  `ai_score` decimal(4,3) NOT NULL COMMENT '0.000~1.000',
  `ai_reasons` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Explainable AI: 추천 사유',
  `bid_hourly` decimal(10,2) DEFAULT NULL COMMENT '돌봄전문가 입찰 시급',
  `bid_note` varchar(255) DEFAULT NULL COMMENT '입찰 메모(경력/조건 어필)',
  `bid_status` enum('none','invited','bid','withdrawn') NOT NULL DEFAULT 'none' COMMENT 'none=입찰무관, invited=입찰요청됨, bid=입찰완료, withdrawn=철회',
  `bid_at` timestamp NULL DEFAULT NULL,
  `rank` tinyint(4) NOT NULL COMMENT '1~5순위',
  `response` enum('pending','accepted','rejected','expired') NOT NULL DEFAULT 'pending',
  `responded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `offered_at` timestamp NULL DEFAULT NULL COMMENT '보호자가 이 후보를 지정한 시각',
  `offer_expires_at` timestamp NULL DEFAULT NULL COMMENT '응답 마감 — 지나면 matching:watch 가 자동 거절',
  PRIMARY KEY (`id`),
  UNIQUE KEY `match_candidates_request_id_caregiver_id_unique` (`request_id`,`caregiver_id`),
  KEY `match_candidates_caregiver_id_foreign` (`caregiver_id`),
  KEY `match_candidates_request_id_rank_index` (`request_id`,`rank`),
  KEY `match_candidates_response_offer_expires_at_index` (`response`,`offer_expires_at`),
  CONSTRAINT `match_candidates_caregiver_id_foreign` FOREIGN KEY (`caregiver_id`) REFERENCES `caregivers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `match_candidates_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `match_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `match_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `match_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `guardian_id` bigint(20) unsigned NOT NULL,
  `senior_id` bigint(20) unsigned DEFAULT NULL,
  `postpartum_client_id` bigint(20) unsigned DEFAULT NULL COMMENT '산모 ID (postpartum 도메인 시)',
  `childcare_child_id` bigint(20) unsigned DEFAULT NULL COMMENT '아이돌봄 아동 ID (childcare 도메인 시)',
  `mental_care_client_id` bigint(20) unsigned DEFAULT NULL COMMENT '마음돌봄 대상 ID (mental_care 도메인 시)',
  `nursing_patient_id` bigint(20) unsigned DEFAULT NULL COMMENT '간병 환자 ID (nursing 도메인 시)',
  `service_address_id` bigint(20) unsigned DEFAULT NULL COMMENT '가사 주소 ID (housekeeping 도메인 시)',
  `category_id` bigint(20) unsigned NOT NULL,
  `mode` enum('normal','emergency','recurring') NOT NULL,
  `service_domain` enum('senior','postpartum','nursing','housekeeping','living_support','childcare','mental_care') NOT NULL DEFAULT 'senior' COMMENT '서비스 도메인',
  `scheduled_start` timestamp NOT NULL DEFAULT current_timestamp(),
  `duration_min` int(10) unsigned NOT NULL COMMENT '소요 시간(분)',
  `recurrence_rule` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'RRULE (정기 매칭)',
  `special_request` text DEFAULT NULL,
  `requirements` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '도메인별 가변 요구사항(교대형태/석션, 평수/사진요구 등)',
  `price_estimate` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '요청 시점 적정가 스냅샷 {floor,suggested,ceil,n_samples,inputs,breakdown}',
  `budget_hourly` decimal(10,2) DEFAULT NULL COMMENT '보호자 희망 상한 시급(선택)',
  `status` enum('open','matching','matched','expired','cancelled') NOT NULL DEFAULT 'open',
  `matched_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `unmatched_alerted_at` timestamp NULL DEFAULT NULL COMMENT '장시간 미매칭 관리자 알림 보낸 시각',
  PRIMARY KEY (`id`),
  KEY `match_requests_guardian_id_foreign` (`guardian_id`),
  KEY `match_requests_senior_id_foreign` (`senior_id`),
  KEY `match_requests_category_id_foreign` (`category_id`),
  KEY `match_requests_status_scheduled_start_index` (`status`,`scheduled_start`),
  KEY `match_requests_mode_index` (`mode`),
  KEY `idx_match_requests_service_domain` (`service_domain`),
  KEY `idx_match_requests_pp_client` (`postpartum_client_id`),
  KEY `match_requests_nursing_patient_id_foreign` (`nursing_patient_id`),
  KEY `match_requests_service_address_id_foreign` (`service_address_id`),
  KEY `match_requests_childcare_child_id_foreign` (`childcare_child_id`),
  KEY `match_requests_mental_care_client_id_foreign` (`mental_care_client_id`),
  CONSTRAINT `fk_match_requests_pp_client` FOREIGN KEY (`postpartum_client_id`) REFERENCES `postpartum_clients` (`id`),
  CONSTRAINT `match_requests_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`),
  CONSTRAINT `match_requests_childcare_child_id_foreign` FOREIGN KEY (`childcare_child_id`) REFERENCES `children` (`id`),
  CONSTRAINT `match_requests_guardian_id_foreign` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`) ON DELETE CASCADE,
  CONSTRAINT `match_requests_mental_care_client_id_foreign` FOREIGN KEY (`mental_care_client_id`) REFERENCES `mental_care_clients` (`id`),
  CONSTRAINT `match_requests_nursing_patient_id_foreign` FOREIGN KEY (`nursing_patient_id`) REFERENCES `nursing_patients` (`id`),
  CONSTRAINT `match_requests_senior_id_foreign` FOREIGN KEY (`senior_id`) REFERENCES `seniors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `match_requests_service_address_id_foreign` FOREIGN KEY (`service_address_id`) REFERENCES `service_addresses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `matches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `matches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` bigint(20) unsigned NOT NULL,
  `caregiver_id` bigint(20) unsigned NOT NULL,
  `scheduled_start` timestamp NOT NULL DEFAULT current_timestamp(),
  `scheduled_end` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `hourly_rate` decimal(10,2) NOT NULL,
  `estimated_amount` decimal(10,2) NOT NULL,
  `status` enum('confirmed','in_progress','completed','cancelled','no_show') NOT NULL DEFAULT 'confirmed',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `is_manual` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `matches_request_id_foreign` (`request_id`),
  KEY `matches_caregiver_id_foreign` (`caregiver_id`),
  KEY `matches_status_index` (`status`),
  KEY `matches_scheduled_start_index` (`scheduled_start`),
  CONSTRAINT `matches_caregiver_id_foreign` FOREIGN KEY (`caregiver_id`) REFERENCES `caregivers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `matches_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `match_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `member_blacklist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `member_blacklist` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `phone_hash` char(64) NOT NULL COMMENT 'HMAC-SHA256(숫자만 휴대폰, APP_KEY)',
  `role` varchar(20) DEFAULT NULL,
  `reason` text NOT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `released_by` bigint(20) unsigned DEFAULT NULL,
  `release_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_blacklist_user_id_foreign` (`user_id`),
  KEY `member_blacklist_created_by_foreign` (`created_by`),
  KEY `member_blacklist_released_by_foreign` (`released_by`),
  KEY `member_blacklist_phone_hash_index` (`phone_hash`),
  CONSTRAINT `member_blacklist_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `member_blacklist_released_by_foreign` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `member_blacklist_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mental_care_clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mental_care_clients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `guardian_id` bigint(20) unsigned NOT NULL,
  `name` varchar(50) NOT NULL,
  `birth_date` date DEFAULT NULL,
  `gender` enum('M','F') DEFAULT NULL,
  `relation` varchar(20) DEFAULT NULL COMMENT '본인/가족 관계',
  `home_address` varchar(255) NOT NULL,
  `home_lat` decimal(10,7) DEFAULT NULL,
  `home_lng` decimal(10,7) DEFAULT NULL,
  `special_notes` text DEFAULT NULL COMMENT '특이사항(암호화)',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mental_care_clients_guardian_id_foreign` (`guardian_id`),
  KEY `mental_care_clients_home_lat_index` (`home_lat`),
  CONSTRAINT `mental_care_clients_guardian_id_foreign` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mentor_pairings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mentor_pairings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `mentor_caregiver_id` bigint(20) unsigned NOT NULL,
  `mentee_caregiver_id` bigint(20) unsigned NOT NULL,
  `started_at` date NOT NULL,
  `ended_at` date DEFAULT NULL,
  `status` enum('active','completed','terminated') NOT NULL DEFAULT 'active',
  `monthly_incentive` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sessions_observed` int(11) NOT NULL DEFAULT 0 COMMENT '동반 출근',
  `video_calls` int(11) NOT NULL DEFAULT 0,
  `mentee_first_30days_sessions` int(11) NOT NULL DEFAULT 0,
  `mentee_first_30days_avg_rating` decimal(3,2) DEFAULT NULL,
  `mentor_self_rating` int(11) DEFAULT NULL,
  `mentee_rating_of_mentor` int(11) DEFAULT NULL,
  `closing_note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mentor_pairings_mentor_caregiver_id_index` (`mentor_caregiver_id`),
  KEY `mentor_pairings_mentee_caregiver_id_index` (`mentee_caregiver_id`),
  KEY `mentor_pairings_status_index` (`status`),
  CONSTRAINT `mentor_pairings_mentee_caregiver_id_foreign` FOREIGN KEY (`mentee_caregiver_id`) REFERENCES `caregivers` (`id`),
  CONSTRAINT `mentor_pairings_mentor_caregiver_id_foreign` FOREIGN KEY (`mentor_caregiver_id`) REFERENCES `caregivers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `message_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `message_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `notification_id` bigint(20) unsigned DEFAULT NULL,
  `template` varchar(40) NOT NULL COMMENT 'CAREN_* 템플릿 키 또는 SMS_OTP 등',
  `channel` varchar(10) NOT NULL COMMENT 'alimtalk|sms|lms',
  `status` varchar(10) NOT NULL COMMENT 'sent|failed|stub|skipped',
  `phone_masked` varchar(20) DEFAULT NULL,
  `result_code` varchar(20) DEFAULT NULL,
  `result_message` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `message_logs_user_id_foreign` (`user_id`),
  KEY `message_logs_template_created_at_index` (`template`,`created_at`),
  KEY `message_logs_notification_id_index` (`notification_id`),
  CONSTRAINT `message_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mock_interview_qa`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mock_interview_qa` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `mock_interview_id` bigint(20) unsigned NOT NULL,
  `question_text` text NOT NULL,
  `question_category` enum('greeting','experience','emergency','communication','difficult_case','self_intro') NOT NULL,
  `answer_text` text DEFAULT NULL,
  `answer_audio_url` varchar(500) DEFAULT NULL,
  `answer_duration_sec` int(11) DEFAULT NULL,
  `eval_specificity` decimal(3,2) DEFAULT NULL,
  `eval_warmth` decimal(3,2) DEFAULT NULL,
  `eval_expertise` decimal(3,2) DEFAULT NULL,
  `eval_emergency` decimal(3,2) DEFAULT NULL,
  `eval_communication` decimal(3,2) DEFAULT NULL,
  `sample_answer` text DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `asked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `answered_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mock_interview_qa_mock_interview_id_index` (`mock_interview_id`),
  KEY `mock_interview_qa_question_category_index` (`question_category`),
  CONSTRAINT `mock_interview_qa_mock_interview_id_foreign` FOREIGN KEY (`mock_interview_id`) REFERENCES `mock_interviews` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mock_interviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mock_interviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `caregiver_id` bigint(20) unsigned NOT NULL,
  `target_domain` enum('senior','postpartum','nursing','housekeeping','living_support','childcare','mental_care') NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ended_at` timestamp NULL DEFAULT NULL,
  `question_count` int(11) NOT NULL DEFAULT 0,
  `score_specificity` decimal(3,2) DEFAULT NULL COMMENT '구체성 1-5',
  `score_warmth` decimal(3,2) DEFAULT NULL COMMENT '따뜻함',
  `score_expertise` decimal(3,2) DEFAULT NULL COMMENT '전문성',
  `score_emergency` decimal(3,2) DEFAULT NULL COMMENT '응급 대응',
  `score_communication` decimal(3,2) DEFAULT NULL COMMENT '커뮤니케이션',
  `overall_score` decimal(3,2) DEFAULT NULL,
  `feedback_report` text DEFAULT NULL,
  `recommended_lessons` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mock_interviews_caregiver_id_index` (`caregiver_id`),
  KEY `mock_interviews_overall_score_index` (`overall_score`),
  CONSTRAINT `mock_interviews_caregiver_id_foreign` FOREIGN KEY (`caregiver_id`) REFERENCES `caregivers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `monthly_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `monthly_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `month` char(7) NOT NULL COMMENT 'YYYY-MM (한국 시각 기준 달)',
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT '매출·결제·정산·매칭·돌봄·회원·후기·KPI 집계',
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `monthly_reports_month_unique` (`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `newborn_anomaly_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `newborn_anomaly_alerts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `newborn_id` bigint(20) unsigned NOT NULL,
  `detected_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `risk_type` enum('weight_loss','jaundice','feeding_low','temperature','overall') NOT NULL,
  `severity` enum('low','medium','high','critical') NOT NULL,
  `score` decimal(5,2) NOT NULL COMMENT '0~100',
  `triggers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT '발생한 룰 목록',
  `recommendations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `notified_at` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolved_by` bigint(20) unsigned DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `newborn_anomaly_alerts_newborn_id_index` (`newborn_id`),
  KEY `newborn_anomaly_alerts_severity_resolved_at_index` (`severity`,`resolved_at`),
  CONSTRAINT `newborn_anomaly_alerts_newborn_id_foreign` FOREIGN KEY (`newborn_id`) REFERENCES `newborns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `newborn_daily_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `newborn_daily_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `newborn_id` bigint(20) unsigned NOT NULL,
  `care_session_id` bigint(20) unsigned DEFAULT NULL,
  `log_datetime` datetime NOT NULL,
  `log_type` enum('feeding','diaper','sleep','weight','jaundice','temperature','note') NOT NULL,
  `feeding_type` enum('breast_left','breast_right','bottle_breast','bottle_formula') DEFAULT NULL,
  `feeding_volume_ml` int(11) DEFAULT NULL,
  `feeding_duration_min` int(11) DEFAULT NULL,
  `diaper_type` enum('urine','stool','both') DEFAULT NULL,
  `stool_color` varchar(20) DEFAULT NULL COMMENT 'yellow/green/dark/bloody',
  `sleep_start` datetime DEFAULT NULL,
  `sleep_end` datetime DEFAULT NULL,
  `weight_g` int(11) DEFAULT NULL,
  `jaundice_level` int(11) DEFAULT NULL COMMENT '1~5 단계',
  `body_temperature` decimal(3,1) DEFAULT NULL,
  `note_text` text DEFAULT NULL,
  `note_audio_url` varchar(500) DEFAULT NULL,
  `is_anomaly` tinyint(1) NOT NULL DEFAULT 0,
  `anomaly_score` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `newborn_daily_logs_newborn_id_log_datetime_index` (`newborn_id`,`log_datetime`),
  KEY `newborn_daily_logs_log_type_index` (`log_type`),
  KEY `newborn_daily_logs_is_anomaly_index` (`is_anomaly`),
  CONSTRAINT `newborn_daily_logs_newborn_id_foreign` FOREIGN KEY (`newborn_id`) REFERENCES `newborns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `newborns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `newborns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `postpartum_client_id` bigint(20) unsigned NOT NULL,
  `name` varchar(50) NOT NULL,
  `gender` enum('M','F') NOT NULL,
  `birth_datetime` datetime NOT NULL,
  `birth_weight_g` int(11) NOT NULL COMMENT '출생 체중 (g)',
  `birth_height_cm` decimal(4,1) DEFAULT NULL,
  `gestational_age_weeks` int(11) DEFAULT NULL COMMENT '재태 주수',
  `gestational_age_days` int(11) DEFAULT NULL,
  `birth_order` int(11) NOT NULL DEFAULT 1 COMMENT '다태아 순번',
  `apgar_1min` int(11) DEFAULT NULL,
  `apgar_5min` int(11) DEFAULT NULL,
  `nicu_days` int(11) DEFAULT NULL,
  `special_conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `is_alive` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `newborns_postpartum_client_id_index` (`postpartum_client_id`),
  KEY `newborns_birth_datetime_index` (`birth_datetime`),
  CONSTRAINT `newborns_postpartum_client_id_foreign` FOREIGN KEY (`postpartum_client_id`) REFERENCES `postpartum_clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `type` varchar(50) NOT NULL COMMENT 'MATCH_CONFIRMED, ANOMALY_HIGH 등',
  `title` varchar(200) NOT NULL,
  `body` text NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_user_id_is_read_index` (`user_id`,`is_read`),
  KEY `notifications_type_index` (`type`),
  CONSTRAINT `notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `nursing_patients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nursing_patients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `guardian_id` bigint(20) unsigned NOT NULL,
  `name` varchar(50) NOT NULL,
  `birth_date` date NOT NULL,
  `gender` enum('M','F') NOT NULL,
  `hospital_name` varchar(100) NOT NULL,
  `hospital_address` varchar(255) NOT NULL,
  `hospital_lat` decimal(10,7) DEFAULT NULL,
  `hospital_lng` decimal(10,7) DEFAULT NULL,
  `ward_room` varchar(50) DEFAULT NULL COMMENT '병동/호실',
  `mobility` enum('independent','assisted','bedridden') NOT NULL DEFAULT 'assisted' COMMENT '거동 상태',
  `diseases` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `care_requirements` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '석션/욕창/식사보조/격리 등',
  `special_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `nursing_patients_guardian_id_foreign` (`guardian_id`),
  KEY `nursing_patients_hospital_lat_hospital_lng_index` (`hospital_lat`,`hospital_lng`),
  CONSTRAINT `nursing_patients_guardian_id_foreign` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `organizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `organizations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `biz_no` varchar(12) NOT NULL COMMENT '사업자등록번호',
  `name` varchar(100) NOT NULL,
  `representative` varchar(50) NOT NULL,
  `address` varchar(255) NOT NULL,
  `contact_phone` varchar(20) NOT NULL,
  `biz_type` varchar(40) NOT NULL DEFAULT 'other',
  `certifications` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '자격증 목록',
  `status` enum('pending','active','suspended') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `organizations_biz_no_unique` (`biz_no`),
  KEY `organizations_user_id_foreign` (`user_id`),
  CONSTRAINT `organizations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` bigint(20) unsigned NOT NULL,
  `item_type` enum('self_pay','ltc_pay','surcharge','discount') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `description` varchar(200) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payment_items_payment_id_foreign` (`payment_id`),
  CONSTRAINT `payment_items_payment_id_foreign` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `guardian_id` bigint(20) unsigned NOT NULL,
  `match_id` bigint(20) unsigned NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `amount_self_pay` decimal(10,2) NOT NULL COMMENT '자비 (본인부담금)',
  `amount_ltc_pay` decimal(10,2) NOT NULL COMMENT '장기요양 청구분',
  `method` varchar(30) NOT NULL COMMENT 'card, account, voucher_only',
  `pg_provider` varchar(30) DEFAULT NULL,
  `pg_tid` varchar(100) DEFAULT NULL,
  `idempotency_key` varchar(64) DEFAULT NULL,
  `status` enum('pending','paid','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  `pg_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `pg_order_id` varchar(64) DEFAULT NULL COMMENT 'PG 주문번호(토스 orderId)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `payments_pg_tid_unique` (`pg_tid`),
  UNIQUE KEY `payments_idempotency_key_unique` (`idempotency_key`),
  UNIQUE KEY `payments_pg_order_id_unique` (`pg_order_id`),
  KEY `payments_guardian_id_foreign` (`guardian_id`),
  KEY `payments_match_id_foreign` (`match_id`),
  KEY `payments_status_index` (`status`),
  KEY `payments_paid_at_index` (`paid_at`),
  CONSTRAINT `payments_guardian_id_foreign` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_match_id_foreign` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `postpartum_chatbot_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `postpartum_chatbot_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `role` enum('user','assistant') NOT NULL,
  `content` text NOT NULL,
  `sources` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'RAG 검색 출처',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `postpartum_chatbot_messages_session_id_index` (`session_id`),
  CONSTRAINT `postpartum_chatbot_messages_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `postpartum_chatbot_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `postpartum_chatbot_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `postpartum_chatbot_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `postpartum_client_id` bigint(20) unsigned NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ended_at` timestamp NULL DEFAULT NULL,
  `message_count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `postpartum_chatbot_sessions_postpartum_client_id_index` (`postpartum_client_id`),
  CONSTRAINT `postpartum_chatbot_sessions_postpartum_client_id_foreign` FOREIGN KEY (`postpartum_client_id`) REFERENCES `postpartum_clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `postpartum_clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `postpartum_clients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL COMMENT '산모 또는 가족 보호자',
  `name` varchar(50) NOT NULL,
  `name_encrypted` blob DEFAULT NULL,
  `phone_encrypted` blob NOT NULL,
  `birth_date` date NOT NULL,
  `address` varchar(500) NOT NULL,
  `address_detail` varchar(200) DEFAULT NULL,
  `region_code` varchar(20) NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `delivery_date` date NOT NULL COMMENT '출산일',
  `delivery_type` enum('natural','cesarean','vbac') NOT NULL COMMENT '자연/제왕/제왕후자연',
  `is_first_baby` tinyint(1) NOT NULL DEFAULT 1,
  `is_multiple_birth` tinyint(1) NOT NULL DEFAULT 0,
  `breastfeeding_intent` enum('exclusive','mixed','formula','undecided') NOT NULL DEFAULT 'undecided',
  `pregnancy_complications` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `postpartum_conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `medications` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `voucher_grade` enum('a_type','b_type','c_type','d_type','e_type') DEFAULT NULL COMMENT '가/나/다/라/마형',
  `voucher_self_pay_rate` decimal(5,4) DEFAULT NULL,
  `voucher_total_days` int(11) DEFAULT NULL,
  `voucher_used_days` int(11) NOT NULL DEFAULT 0,
  `voucher_amount_total` decimal(12,2) DEFAULT NULL,
  `voucher_amount_used` decimal(12,2) NOT NULL DEFAULT 0.00,
  `voucher_certified_at` date DEFAULT NULL,
  `status` enum('active','completed','cancelled') NOT NULL DEFAULT 'active',
  `special_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `postpartum_clients_user_id_index` (`user_id`),
  KEY `postpartum_clients_branch_id_index` (`branch_id`),
  KEY `postpartum_clients_region_code_index` (`region_code`),
  KEY `postpartum_clients_delivery_date_index` (`delivery_date`),
  KEY `postpartum_clients_status_index` (`status`),
  CONSTRAINT `postpartum_clients_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `postpartum_clients_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pricing_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pricing_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` bigint(20) unsigned NOT NULL,
  `region_code` varchar(16) DEFAULT NULL COMMENT '시군구 코드, null=전국 기본',
  `region_index` decimal(4,2) NOT NULL DEFAULT 1.00 COMMENT '지역 물가 계수',
  `night_mult` decimal(4,2) NOT NULL DEFAULT 1.30 COMMENT '야간(22-06) 배수',
  `holiday_mult` decimal(4,2) NOT NULL DEFAULT 1.50 COMMENT '공휴일/일요일 배수',
  `emergency_mult` decimal(4,2) NOT NULL DEFAULT 1.20 COMMENT '긴급 요청 배수',
  `acuity_addons` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '{치매:1500, 와상:2000, 석션:3000} 시급 가산',
  `min_hourly` decimal(10,2) NOT NULL DEFAULT 10030.00 COMMENT '법정 최저시급 하한',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pricing_rules_category_id_region_code_unique` (`category_id`,`region_code`),
  CONSTRAINT `pricing_rules_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `progress_tracks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `progress_tracks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `enrollment_id` bigint(20) unsigned NOT NULL,
  `course_lesson_id` bigint(20) unsigned NOT NULL,
  `progress_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `last_position_sec` int(11) DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `total_watch_seconds` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `progress_tracks_enrollment_id_course_lesson_id_unique` (`enrollment_id`,`course_lesson_id`),
  KEY `progress_tracks_course_lesson_id_foreign` (`course_lesson_id`),
  CONSTRAINT `progress_tracks_course_lesson_id_foreign` FOREIGN KEY (`course_lesson_id`) REFERENCES `course_lessons` (`id`),
  CONSTRAINT `progress_tracks_enrollment_id_foreign` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `match_id` bigint(20) unsigned NOT NULL,
  `reviewer_id` bigint(20) unsigned NOT NULL,
  `reviewer_role` enum('guardian','caregiver') NOT NULL,
  `rating` tinyint(4) NOT NULL COMMENT '1~5',
  `comment` text DEFAULT NULL,
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `admin_reply` text DEFAULT NULL,
  `replied_at` timestamp NULL DEFAULT NULL,
  `replied_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `scores` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '도메인별 평가 항목 점수 {항목키: 1~5} — config/review_criteria.php',
  `flagged_at` timestamp NULL DEFAULT NULL COMMENT '2점 이하 → 운영팀 알림 보낸 시각(답변 SLA 기준점)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `reviews_match_id_reviewer_id_unique` (`match_id`,`reviewer_id`),
  KEY `reviews_reviewer_id_foreign` (`reviewer_id`),
  KEY `reviews_match_id_reviewer_role_index` (`match_id`,`reviewer_role`),
  CONSTRAINT `reviews_match_id_foreign` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reviews_reviewer_id_foreign` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `self_introduction_interviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `self_introduction_interviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `caregiver_id` bigint(20) unsigned NOT NULL,
  `interview_audio_url` varchar(500) NOT NULL,
  `duration_sec` int(11) NOT NULL,
  `transcript` text DEFAULT NULL,
  `transcript_confidence` decimal(5,4) DEFAULT NULL,
  `extracted_motivation` text DEFAULT NULL,
  `extracted_strengths` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `extracted_target_domain` enum('senior','postpartum','both','nursing','housekeeping','living_support','childcare','mental_care') DEFAULT NULL,
  `extracted_keywords` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `used_for_resume_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `self_introduction_interviews_caregiver_id_index` (`caregiver_id`),
  KEY `self_introduction_interviews_used_for_resume_id_index` (`used_for_resume_id`),
  CONSTRAINT `self_introduction_interviews_caregiver_id_foreign` FOREIGN KEY (`caregiver_id`) REFERENCES `caregivers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `self_introduction_interviews_used_for_resume_id_foreign` FOREIGN KEY (`used_for_resume_id`) REFERENCES `caregiver_resumes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seniors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `seniors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `guardian_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(50) NOT NULL,
  `birth_date` date NOT NULL,
  `gender` enum('M','F') NOT NULL,
  `care_grade` tinyint(4) NOT NULL COMMENT '1~5등급, 0=등급외',
  `care_grade_no` text DEFAULT NULL COMMENT '장기요양인정번호(암호화)',
  `diseases` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '질환 목록 (치매, 당뇨 등)',
  `special_notes` text DEFAULT NULL,
  `home_address` varchar(255) NOT NULL,
  `home_lat` decimal(10,7) DEFAULT NULL,
  `home_lng` decimal(10,7) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `seniors_guardian_id_foreign` (`guardian_id`),
  KEY `seniors_user_id_foreign` (`user_id`),
  KEY `seniors_care_grade_index` (`care_grade`),
  KEY `seniors_home_lat_home_lng_index` (`home_lat`,`home_lng`),
  CONSTRAINT `seniors_guardian_id_foreign` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`) ON DELETE CASCADE,
  CONSTRAINT `seniors_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `guardian_id` bigint(20) unsigned NOT NULL,
  `label` varchar(50) NOT NULL COMMENT '표시명(''우리집'' 등)',
  `address` varchar(255) NOT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `dwelling_type` enum('apartment','villa','house','officetel','other') NOT NULL DEFAULT 'apartment',
  `size_m2` smallint(5) unsigned DEFAULT NULL,
  `has_pets` tinyint(1) NOT NULL DEFAULT 0,
  `entry_note` text DEFAULT NULL COMMENT '출입방법/주차',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `service_addresses_guardian_id_foreign` (`guardian_id`),
  CONSTRAINT `service_addresses_guardian_id_foreign` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL COMMENT 'VISIT_CARE, COMPANION, NIGHT_CARE 등',
  `domain` varchar(20) NOT NULL DEFAULT 'senior' COMMENT '서비스 도메인(senior/nursing/housekeeping)',
  `name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `base_rate` decimal(10,2) NOT NULL COMMENT '기준 시급',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_categories_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settlement_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settlement_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `settlement_id` bigint(20) unsigned NOT NULL,
  `session_id` bigint(20) unsigned NOT NULL,
  `hours` decimal(5,2) NOT NULL,
  `hourly_rate` decimal(10,2) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `surcharge` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '할증',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `settlement_items_session_id_foreign` (`session_id`),
  KEY `settlement_items_settlement_id_index` (`settlement_id`),
  CONSTRAINT `settlement_items_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `care_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `settlement_items_settlement_id_foreign` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settlements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `caregiver_id` bigint(20) unsigned NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `gross_amount` decimal(12,2) NOT NULL COMMENT '세전 정산액',
  `withholding_tax_3_3` decimal(12,2) NOT NULL COMMENT '3.3% 원천징수',
  `net_amount` decimal(12,2) NOT NULL COMMENT '실지급액',
  `hometax_filing_no` varchar(50) DEFAULT NULL,
  `bank_tx_id` varchar(100) DEFAULT NULL,
  `status` enum('draft','confirmed','paid','failed') NOT NULL DEFAULT 'draft',
  `confirmed_by` bigint(20) unsigned DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `caregiver_ack_at` timestamp NULL DEFAULT NULL COMMENT '돌봄전문가가 명세서를 확인(승인)한 시각',
  `dispute_reason` text DEFAULT NULL,
  `disputed_at` timestamp NULL DEFAULT NULL,
  `dispute_status` varchar(10) DEFAULT NULL COMMENT 'open|resolved',
  `dispute_reply` text DEFAULT NULL,
  `dispute_resolved_at` timestamp NULL DEFAULT NULL,
  `dispute_resolved_by` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settlements_caregiver_id_period_start_unique` (`caregiver_id`,`period_start`),
  KEY `settlements_confirmed_by_foreign` (`confirmed_by`),
  KEY `settlements_status_index` (`status`),
  KEY `settlements_dispute_resolved_by_foreign` (`dispute_resolved_by`),
  CONSTRAINT `settlements_caregiver_id_foreign` FOREIGN KEY (`caregiver_id`) REFERENCES `caregivers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `settlements_confirmed_by_foreign` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `settlements_dispute_resolved_by_foreign` FOREIGN KEY (`dispute_resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `social_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `social_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(20) NOT NULL COMMENT 'kakao|google',
  `provider_user_id` varchar(100) NOT NULL,
  `email` varchar(190) DEFAULT NULL COMMENT '제공자가 준 이메일(참고용)',
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `social_accounts_provider_provider_user_id_unique` (`provider`,`provider_user_id`),
  UNIQUE KEY `social_accounts_user_id_provider_unique` (`user_id`,`provider`),
  CONSTRAINT `social_accounts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stt_evaluations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stt_evaluations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_label` varchar(100) NOT NULL COMMENT '평가 실행 이름',
  `term_set` varchar(50) NOT NULL COMMENT 'CareTerm 기준 목록 이름',
  `stt_engine` varchar(100) NOT NULL COMMENT '평가한 STT 엔진·모델',
  `samples` int(10) unsigned NOT NULL COMMENT '평가 음성 수',
  `terms_total` int(10) unsigned NOT NULL COMMENT '정답 전사에 등장한 케어용어 수',
  `terms_correct` int(10) unsigned NOT NULL COMMENT 'STT가 맞게 전사한 케어용어 수',
  `rate` decimal(5,2) NOT NULL COMMENT '정상 인식률(%)',
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '용어별·음성별 결과',
  `evaluated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `name` varchar(50) NOT NULL,
  `role` enum('guardian','caregiver','organization','admin') NOT NULL,
  `password` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `phone_verified_at` timestamp NULL DEFAULT NULL,
  `status` enum('active','suspended','withdrawn') NOT NULL DEFAULT 'active',
  `fcm_token` varchar(255) DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `totp_secret` text DEFAULT NULL COMMENT '2단계 인증 비밀키(암호화)',
  `totp_enabled_at` timestamp NULL DEFAULT NULL COMMENT '2단계 인증 등록 시각',
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_phone_unique` (`phone`),
  KEY `users_role_index` (`role`),
  KEY `users_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vital_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vital_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `senior_id` bigint(20) unsigned NOT NULL,
  `session_id` bigint(20) unsigned DEFAULT NULL,
  `blood_pressure_sys` smallint(5) unsigned DEFAULT NULL COMMENT '수축기 mmHg',
  `blood_pressure_dia` smallint(5) unsigned DEFAULT NULL COMMENT '이완기 mmHg',
  `blood_sugar` smallint(5) unsigned DEFAULT NULL COMMENT '혈당 mg/dL',
  `body_temperature` decimal(4,1) DEFAULT NULL COMMENT '체온 °C',
  `heart_rate` smallint(5) unsigned DEFAULT NULL COMMENT '심박수 bpm',
  `weight` decimal(5,2) DEFAULT NULL COMMENT '체중 kg',
  `measured_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `vital_records_session_id_foreign` (`session_id`),
  KEY `vital_records_senior_id_measured_at_index` (`senior_id`,`measured_at`),
  CONSTRAINT `vital_records_senior_id_foreign` FOREIGN KEY (`senior_id`) REFERENCES `seniors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vital_records_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `care_sessions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voice_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `voice_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `audio_url` varchar(500) NOT NULL COMMENT 'S3 URL',
  `duration_sec` int(10) unsigned NOT NULL,
  `stt_text` text DEFAULT NULL,
  `stt_confidence` decimal(4,3) DEFAULT NULL,
  `status` enum('uploaded','transcribing','transcribed','summarized','failed') NOT NULL DEFAULT 'uploaded',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `voice_logs_session_id_foreign` (`session_id`),
  KEY `voice_logs_status_index` (`status`),
  CONSTRAINT `voice_logs_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `care_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voucher_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `voucher_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `postpartum_client_id` bigint(20) unsigned NOT NULL,
  `voucher_type` enum('mother_newborn_care') NOT NULL DEFAULT 'mother_newborn_care',
  `transaction_type` enum('issue','use','refund','expire') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `days` int(11) DEFAULT NULL,
  `care_session_id` bigint(20) unsigned DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `sba_transaction_id` varchar(100) DEFAULT NULL COMMENT 'SBA 거래번호',
  `sba_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `status` enum('pending','success','failed','cancelled') NOT NULL DEFAULT 'pending',
  `failed_reason` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `voucher_transactions_postpartum_client_id_index` (`postpartum_client_id`),
  KEY `voucher_transactions_care_session_id_index` (`care_session_id`),
  KEY `voucher_transactions_status_index` (`status`),
  KEY `voucher_transactions_sba_transaction_id_index` (`sba_transaction_id`),
  CONSTRAINT `voucher_transactions_postpartum_client_id_foreign` FOREIGN KEY (`postpartum_client_id`) REFERENCES `postpartum_clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'2019_12_14_000001_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'2026_05_01_000001_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'2026_05_01_000002_create_organizations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_05_01_000003_create_guardians_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_05_01_000004_create_seniors_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_05_01_000005_create_caregivers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_05_01_000006_create_admins_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_05_01_000010_create_service_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_05_01_000011_create_match_requests_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_05_01_000012_create_match_candidates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_05_01_000013_create_matches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_05_01_000020_create_care_sessions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_05_01_000021_create_attendance_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_05_01_000022_create_care_activities_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_05_01_000023_create_voice_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_05_01_000024_create_ai_log_summaries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_05_01_000025_create_care_photos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_05_01_000030_create_vital_records_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_05_01_000031_create_health_timeseries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_05_01_000032_create_anomaly_alerts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_05_01_000040_create_ltc_vouchers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_05_01_000041_create_payments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_05_01_000042_create_payment_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_05_01_000043_create_settlements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_05_01_000044_create_settlement_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_05_01_000050_create_ai_models_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_05_01_000051_create_ai_recommendations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_05_01_000052_create_ai_inference_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_05_01_000053_create_reviews_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_05_01_000054_create_chatbot_sessions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_05_01_000055_create_chatbot_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_05_01_000056_create_notifications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_05_01_000057_create_audit_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_05_01_000001_alter_caregivers_add_phase2_columns',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_05_01_000002_create_branches_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_05_01_000003_add_caregivers_branch_fk',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_05_01_000004_alter_match_requests_add_postpartum',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_05_01_000005_create_postpartum_clients_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_05_01_000006_create_newborns_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_05_01_000007_create_newborn_daily_logs_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_05_01_000008_create_epds_assessments_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_05_01_000009_create_voucher_transactions_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_05_01_000010_create_postpartum_chatbot_tables',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_05_01_000011_create_newborn_anomaly_alerts_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_05_01_000012_create_courses_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_05_01_000013_create_lessons_instructors_sessions_tables',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_05_01_000014_create_enrollments_attendance_progress_tables',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_05_01_000015_create_assessments_certifications_supports_tables',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_05_01_000016_create_d4_career_tables',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_05_01_000017_create_phase4_franchise_tables',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_06_10_000001_add_review_status_to_care_sessions',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_06_11_013132_create_failed_jobs_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_06_12_100001_extend_service_domain_enums',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_06_12_100002_create_nursing_patients_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_06_12_100003_create_service_addresses_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_06_12_100004_alter_match_requests_add_vertical_refs',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_06_12_100005_alter_caregivers_license_nullable',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_06_12_100006_seed_vertical_service_categories',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_06_12_110001_add_session_schedule_and_category_domain',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_06_12_170001_extend_education_domain_enums',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_06_12_180001_remove_implicit_on_update_timestamps',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_06_14_000010_add_reply_to_reviews_table',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_06_26_000001_create_caregiver_invites_table',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_06_26_000002_add_caregiver_browse_and_blocks',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_06_27_000001_add_intent_to_guardians',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_06_27_100001_create_pricing_rules_and_request_price_fields',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_06_27_100002_seed_national_pricing_rules',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_06_27_100003_add_bidding_to_candidates_and_caregivers',16);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_06_28_100001_seed_regional_pricing_rules',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_06_29_000001_rename_housekeeping_to_living_support',18);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_06_29_000002_seed_postpartum_categories',19);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_06_29_000003_create_children_and_open_childcare',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_06_29_000004_create_mental_care_clients_and_open',21);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_06_30_000001_finalize_domain_rates',22);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_06_30_000002_add_license_type_to_caregivers',23);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_06_30_000003_create_caregiver_favorites',24);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_09_28_000001_add_care_log_kpi_timestamps',25);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_09_28_000002_add_hash_chain_to_audit_logs',26);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_09_28_000003_add_totp_to_users',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_09_28_000004_admin_permission_levels_five',28);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2026_09_28_000005_encrypt_medical_fields',29);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_09_28_000006_add_verification_to_ai_log_summaries',30);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_09_28_000007_add_pg_order_id_to_payments',31);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_09_28_000008_create_social_accounts_table',32);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2026_09_28_000009_create_message_logs_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2026_09_28_000010_add_scores_to_reviews',34);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'2026_09_28_000011_create_caregiver_documents_table',35);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'2026_09_28_000012_add_matching_timers',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'2026_09_28_000013_add_journal_chips_to_care_sessions',37);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (90,'2026_09_28_000014_create_monthly_reports_table',38);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (91,'2026_09_29_000001_add_out_of_range_to_attendance_logs',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (92,'2026_09_29_000002_add_edit_fields_to_ai_log_summaries',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (93,'2026_09_29_000003_create_care_log_shares_table',41);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (94,'2026_09_29_000004_add_ack_dispute_to_settlements',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (95,'2026_09_29_000005_create_member_blacklist_table',43);
