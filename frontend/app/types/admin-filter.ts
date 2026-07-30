/**
 * Defines taxonomy and moderation filters exchanged by AdminFilter, AdminFilterCard and /api/admin/filters.
 * The parent ID maps filters.belong_to while moderation filters remain independent roots.
 */
export type AdminFilterType = "theme" | "category" | "subcategory" | "moderation"

export interface AdminFilter {
  id: number
  filter_name: string
  filter_type: AdminFilterType
  belong_to: number | null
  parent_name: string | null
  created_at: string
}
