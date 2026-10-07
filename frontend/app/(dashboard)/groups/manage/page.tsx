import { redirect } from "next/navigation";

/**
 * ⭐ الإدارة اتدمجت في `/groups` — زرار «إدارة» في كل كرت.
 *
 * عشان مايبقاش في صفحتين لنفس المجموعات، حوّلنا ده لتحويل
 * تلقائي لأي حد عنده بوك مارك قديم.
 */
export default function GroupsManagePage() {
  redirect("/groups");
}
