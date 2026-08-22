/**
 * Defines grouped page cases, child reports and comparison context returned by moderation endpoints.
 * AdminModeration consumes these values after PHP joins moderation_cases -> moderation/pages/users/revisions.
 */
import type { CmsBlock } from "~/types/cms/cms"

export type ModerationStatus = "pending" | "reviewed" | "dismissed"
export type ReportedContentType = "page_display" | "page_content"

export interface ModerationReport {
  id: number
  moderation_case_id: number
  reporter_user_id: number
  reporter_username: string
  reported_page_id: number
  reported_filter_content: number | null
  reported_filter_name: string
  reported_user_message: string | null
  reported_media_url: string | null
  reported_content_type: ReportedContentType
  reported_block_id: string
  reported_block_type: CmsBlock["type"] | null
  reported_content_snapshot: CmsBlock | null
  moderation_status: ModerationStatus
  reviewed_by_user_id: number | null
  reviewer_username: string | null
  created_at: string
  updated_at: string
  reviewed_at: string | null
}

export interface ModerationCase {
  id: number
  reported_page_id: number
  moderation_status: ModerationStatus
  reviewed_by_user_id: number | null
  reviewer_username: string | null
  created_at: string
  updated_at: string
  reviewed_at: string | null
  page_owner_user_id: number
  page_owner_username: string
  page_owner_picture: string | null
  page_title: string
  page_status: "public" | "private" | "banned"
  page_is_anonymous: boolean | number
  number_of_likes: number
  number_of_view: number
  number_of_followers: number
  page_description: string | null
  page_picture: string | null
  page_created_at: string
  page_updated_at: string
  report_count: number
  pending_report_count: number
  reviewed_report_count: number
  dismissed_report_count: number
  reports: ModerationReport[]
}

export interface ModerationCaseSnapshot {
  pagecontent: unknown
  reports: Array<{
    report_id: number
    content_type: ReportedContentType
    block_id: string
    block_type: CmsBlock["type"] | null
    created_at: string
  }>
}

export interface ModerationPageContext {
  id: number
  owner_user_id: number
  owner_username: string
  owner_picture: string | null
  page_title: string
  page_status: "public" | "private" | "banned"
  is_anonymous: boolean | number
  number_of_likes: number
  number_of_view: number
  number_of_followers: number
  page_description: string | null
  page_picture: string | null
  pagecontent: unknown
  created_at: string
  updated_at: string
}

export interface ModerationRevision {
  id: number
  revision_number: number
  revision_status: "draft" | "published" | "archived"
  is_current: boolean | number
  pagecontent: unknown
  created_at: string
  updated_at: string
  published_at: string | null
  created_by_username: string | null
}

export interface ModerationCaseContext {
  case: {
    id: number
    reported_page_id: number
    reported_page_snapshot: ModerationCaseSnapshot
    moderation_status: ModerationStatus
  }
  page: ModerationPageContext
  revisions: ModerationRevision[]
  reports: ModerationReport[]
}
