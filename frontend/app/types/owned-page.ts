export type OwnedPageStatus = "public" | "private" | "banned"

export type OwnedPage = {
  id: number
  owner_user_id: number
  page_title: string
  page_status: OwnedPageStatus
  is_anonymous: boolean
  number_of_likes: number
  number_of_view: number
  number_of_followers: number
  page_description: string | null
  page_picture: string | null
  created_at: string
  updated_at: string
}

export type OwnedPageMessage = {
  type: "error" | "success"
  text: string
}
