#!/usr/bin/env bash
# ===========================================================================
# ScrumSpace — rollback ke versi sebelum deploy terakhir.
#   ./rollback.sh
#
# Cara kerja: matikan stack baru (volume DATA TIDAK disentuh), checkout kode
# ke commit sebelum deploy, lalu jalankan ./deploy.sh lagi. Data (database,
# storage, token OAuth) aman karena tersimpan di volume Docker yang tidak
# ikut dihapus.
# ===========================================================================
set -euo pipefail
cd "$(dirname "$0")"

say()  { echo "==> $1"; }
fail() {
    echo ""
    echo "-----------------------------------------------------------------"
    echo " GAGAL: $1"
    [ -n "${2:-}" ] && echo " PERBAIKI: $2"
    echo "-----------------------------------------------------------------"
    exit 1
}

[ -f .deploy-state ] || fail "Tidak ada catatan deploy sebelumnya (.deploy-state tidak ada)." \
    "Matikan stack yang rusak manual: docker compose down  |  lalu jalankan ./deploy.sh dari kode yang benar."
# shellcheck disable=SC1091
source .deploy-state
[ -n "${PREV_COMMIT:-}" ] || fail "Catatan rollback rusak (PREV_COMMIT kosong)."

say "Rollback ke commit $PREV_COMMIT (dideploy pada ${DEPLOYED_AT:-?})..."

say "1/4  Matikan stack baru (volume data TIDAK dihapus)..."
docker compose down
echo "     Stack dimatikan. Volume data tetap utuh."

say "2/4  Kembalikan kode ke versi sebelumnya..."
if ! git diff --quiet || ! git diff --cached --quiet; then
    git stash push -m "rollback-$(date +%F_%H%M%S)" >/dev/null
    echo "     Perubahan lokal disimpan ke stash."
fi
git checkout "$PREV_COMMIT" --quiet
echo "     Kode dikembalikan ke $(git rev-parse --short HEAD)."

say "3/4  Deploy ulang versi lama..."
./deploy.sh

say "4/4  Selesai."
echo ""
echo "-----------------------------------------------------------------"
echo " Rollback selesai. Aplikasi kembali ke versi $PREV_COMMIT."
echo " Catatan: untuk kembali ke versi terbaru: git checkout main && ./deploy.sh"
echo "-----------------------------------------------------------------"
