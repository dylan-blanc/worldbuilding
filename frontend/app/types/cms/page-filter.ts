// Shared filter types mirror GET /filters and page_filters data used by publication and personal-page forms.
export type PageFilterType = "theme" | "category" | "subcategory"

export type PageFilterOption = {
  id: number
  filter_name: string
  filter_type: PageFilterType
  belong_to: number | null
  parent_name: string | null
  filter_id?: number
}
