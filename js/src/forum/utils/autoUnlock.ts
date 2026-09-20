import { getPostId, savedPassword, unlockBlock } from './unlock';

/**
 * After the page has rendered, automatically re-unlock any block the visitor
 * already unlocked during this session, so they don't have to enter the
 * password again.
 */
export function attemptAutoUnlock(): void {
  window.setTimeout(() => {
    document.querySelectorAll<HTMLElement>('.Cipher-box-locked[data-cipher-id]').forEach((box) => {
      const postId = getPostId(box);
      const cipherId = box.dataset.cipherId;

      if (postId == null || !cipherId) return;

      // A remembered password can be rejected (the author rotated it, or a
      // visibility condition no longer holds). Retrying on every DOM mutation
      // would burn through the server's failed-attempt limit and lock the
      // visitor out for the whole window, so each card is attempted once — a
      // re-rendered card is a fresh element and gets a fresh attempt.
      if (box.dataset.cipherAutoUnlock === '1') return;

      const password = savedPassword(postId, cipherId);

      if (password != null) {
        box.dataset.cipherAutoUnlock = '1';

        unlockBlock(postId, cipherId, password).catch(() => {});
      }
    });
  }, 500);
}
