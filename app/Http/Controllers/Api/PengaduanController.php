<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResponPengaduanRequest;
use App\Http\Requests\StorePengaduanRequest;
use App\Http\Requests\UpdateStatusPengaduanRequest;
use App\Models\Pengaduan;
use App\Models\RiwayatRespon;
use App\Mail\PengaduanResponMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PengaduanController extends Controller
{
    /**
     * Get list pengaduan dengan filter & pagination (Admin)
     * GET /api/v1/admin/pengaduan
     *
     * Supports both simple pagination and DataTables server-side format
     */
    public function index(Request $request)
    {
        $query = Pengaduan::query();

        // Filter status
        $query->status($request->status);

        // Search
        $query->search($request->search['value'] ?? $request->search);

        // Filter jenis responden
        $query->jenisResponden($request->jenis_responden);

        // Filter tanggal
        $query->tanggalRange($request->start_date, $request->end_date);

        // Sorting - Support both formats
        // Format 1: sortColumn + sortColumnDir (DataTables style)
        // Format 2: sort + order (simple style)
        if ($request->has('sortColumn')) {
            $sortColumn = $request->sortColumn;
            $sortDir = $request->sortColumnDir ?? 'desc';
        } else {
            $sortColumn = $request->sort ?? 'tanggal';
            $sortDir = $request->order ?? 'DESC';
        }

        // Validate sort column
        $validSorts = ['id', 'tanggal', 'nama', 'status', 'no_pengaduan', 'created_at'];
        if (!in_array($sortColumn, $validSorts)) {
            $sortColumn = 'tanggal';
        }
        $query->orderBy($sortColumn, $sortDir);

        // Pagination - Support both formats
        // Format 1: pageNumber + pageSize (DataTables style, 1-based)
        // Format 2: page + per_page (Laravel style, 1-based)
        if ($request->has('pageNumber')) {
            $pageNumber = max(1, (int)($request->pageNumber ?? 1));
            $pageSize = min((int)($request->pageSize ?? 5), 100);
            $page = $pageNumber; // Laravel juga 1-based
            $perPage = $pageSize;
        } else {
            $page = max(1, (int)($request->page ?? 1));
            $perPage = min((int)($request->per_page ?? 5), 100);
        }

        $result = $query->paginate($perPage, ['*'], 'page', $page);

        // Get counts by status
        $counts = Pengaduan::getCountsByStatus();

        // Response format - DataTables style or simple style
        return response()->json([
            'success' => true,
            'data' => $result->items(),
            'pagination' => [
                'current_page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
                'from' => $result->firstItem(),
                'to' => $result->lastItem(),
            ],
            'counts' => $counts,
        ]);
    }

    /**
     * Store a newly created pengaduan (Public)
     * POST /api/v1/pengaduan
     */
    public function store(StorePengaduanRequest $request)
    {
        try {
            // Handle file upload if exists
            $lampiran = null;
            if ($request->hasFile('lampiran')) {
                $file = $request->file('lampiran');
                $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('pengaduan/' . date('Y/m'), $filename, 'public');
                $lampiran = $path;
            }

            // Generate nomor pengaduan
            $noPengaduan = Pengaduan::generateNomorPengaduan();

            // Create pengaduan
            $pengaduan = Pengaduan::create([
                'no_pengaduan' => $noPengaduan,
                'tanggal' => now()->toDateString(),
                'waktu' => now()->format('H:i'),
                'nama' => $request->nama,
                'email' => $request->email,
                'nohp' => $request->no_hp ?? $request->nohp,
                'jenis_responden' => $request->jenis_responden,
                'aduan' => $request->aduan,
                'status' => 'waiting',
                'dibaca' => false,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pengaduan berhasil dikirim',
                'data' => [
                    'id' => $pengaduan->id,
                    'no_pengaduan' => $pengaduan->no_pengaduan,
                    'nama' => $pengaduan->nama,
                    'aduan' => $pengaduan->aduan,
                    'status' => $pengaduan->status,
                    'tanggal' => $pengaduan->tanggal,
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan pengaduan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detail pengaduan (Admin)
     * GET /api/v1/admin/pengaduan/{id}
     */
    public function show(Request $request, $id)
    {
        $pengaduan = Pengaduan::with(['riwayatRespon'])->findOrFail($id);

        // Mark as read
        if (!$pengaduan->dibaca) {
            $pengaduan->update([
                'dibaca' => true,
                'dibaca_oleh' => $request->user()->name ?? $request->user()->username,
                'dibaca_tanggal' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $pengaduan,
        ]);
    }

    /**
     * Submit respon pengaduan (Admin)
     * POST /api/v1/admin/pengaduan/{id}/respon
     */
    public function respon(Request $request, $id)
    {
        $pengaduan = Pengaduan::findOrFail($id);

        $request->validate([
            'tanggapan' => 'required|min:5',
            'catatan_internal' => 'nullable|max:1000',
            'status' => 'required|in:waiting,processing,completed,archived',
            'kirim_notifikasi' => 'boolean',
        ]);

        // Update pengaduan
        $pengaduan->update([
            'respon_admin' => $request->tanggapan,
            'catatan_internal' => $request->catatan_internal,
            'status' => $request->status,
            'tgl_respon' => now(),
        ]);

        // Add to riwayat
        RiwayatRespon::create([
            'pengaduan_id' => $pengaduan->id,
            'tanggal' => now()->toDateString(),
            'waktu' => now()->format('H:i'),
            'admin' => $request->user()->name ?? $request->user()->username,
            'isi' => $request->tanggapan,
        ]);

        // Kirim notifikasi jika diminta
        if ($request->kirim_notifikasi && $pengaduan->email) {
            Mail::to($pengaduan->email)->send(new PengaduanResponMail($pengaduan, $request->tanggapan));
        }

        return response()->json([
            'success' => true,
            'message' => 'Respon berhasil dikirim',
            'data' => $pengaduan->fresh()->load('riwayatRespon'),
        ]);
    }

    /**
     * Update status pengaduan (Admin)
     * PUT /api/v1/admin/pengaduan/{id}/status
     */
    public function updateStatus(Request $request, $id)
    {
        $pengaduan = Pengaduan::findOrFail($id);

        $request->validate([
            'status' => 'required|in:waiting,processing,completed,archived',
            'catatan' => 'nullable|max:500',
        ]);

        $statusLama = $pengaduan->status;
        $pengaduan->update([
            'status' => $request->status,
        ]);

        // Add catatan to riwayat if provided
        if ($request->catatan) {
            RiwayatRespon::create([
                'pengaduan_id' => $pengaduan->id,
                'tanggal' => now()->toDateString(),
                'waktu' => now()->format('H:i'),
                'admin' => $request->user()->name ?? $request->user()->username,
                'isi' => "Status diubah dari {$statusLama} menjadi {$request->status}. Catatan: {$request->catatan}",
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Status berhasil diupdate',
            'data' => [
                'id' => $pengaduan->id,
                'no_pengaduan' => $pengaduan->no_pengaduan,
                'status_lama' => $statusLama,
                'status_baru' => $pengaduan->status,
            ],
        ]);
    }

    /**
     * Delete/Archive pengaduan (Admin)
     * DELETE /api/v1/admin/pengaduan/{id}
     */
    public function destroy(Request $request, $id)
    {
        $pengaduan = Pengaduan::withTrashed()->findOrFail($id);

        $type = $request->type ?? 'soft';

        if ($type === 'hard') {
            // Permanent delete
            $pengaduan->forceDelete();
            $message = 'Pengaduan berhasil dihapus permanen';
        } else {
            // Soft delete (archive)
            $pengaduan->update(['status' => 'archived']);
            $pengaduan->delete();
            $message = 'Pengaduan berhasil diarsipkan';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    /**
     * Get counts by status (Admin)
     * GET /api/v1/admin/pengaduan/counts
     */
    public function counts()
    {
        $counts = Pengaduan::getCountsByStatus();

        return response()->json([
            'success' => true,
            'data' => $counts,
        ]);
    }
}
