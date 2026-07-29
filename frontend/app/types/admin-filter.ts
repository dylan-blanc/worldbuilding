/**
 * Defines the filter hierarchy exchanged by AdminFilter, AdminFilterCard and /api/admin/filters.
 * The parent ID maps filters.belong_to from the PHP Filter model response.
 */
export type AdminFilterType = "theme" | "category" | "subcategory"

export interface AdminFilter {
  id: number
  filter_name: string
  filter_type: AdminFilterType
  belong_to: number | null
  parent_name: string | null
  created_at: string
}
