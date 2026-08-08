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

      const password = savedPassword(postId, cipherId);

      if (password != null) {
        unlockBlock(postId, cipherId, password).catch(() => {});
      }
    });
  }, 500);
}
