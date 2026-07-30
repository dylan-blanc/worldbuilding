/**
 * Defines reports exchanged by PageDisplay, adminmoderation.vue and the moderation PHP endpoints.
 * Status updates and page history keep the SQL moderation lifecycle explicit in the frontend.
 */
export type ModerationStatus = "pending" | "reviewed" | "dismissed"
export type ReportedContentType = "page_display" | "page_content"

export interface ModerationReport {
  id: number
  reporter_user_id: number
  reporter_username: string
  reported_page_id: number
  page_title: string
  page_status: "public" | "private" | "banned"
  current_media_url: string | null
  reported_filter_content: number
  reported_filter_name: string
  reported_user_message: string | null
  reported_media_url: string | null
  reported_content_type: ReportedContentType
  moderation_status: ModerationStatus
  reviewed_by_user_id: number | null
  reviewer_username: string | null
  created_at: string
  updated_at: string
  reviewed_at: string | null
  report_count: number
}
