/*
  This utility detects URL-like values in page titles before metadata requests reach PageMetadataValidator.
  PageMetadataForm and Freepage share it for immediate feedback, while PHP remains authoritative before SQL writes.
*/
export const containsPageTitleUrl = (value: string) => (
  /(?:https?:\/\/|www\.|(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:com|net|org|io|fr|be|ch|ca|de|es|it|uk|eu|dev|app|online|site|xyz)(?:[/:?#]|\b))/iu.test(value)
)
