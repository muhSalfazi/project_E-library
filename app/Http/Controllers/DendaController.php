<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Peminjaman;
use App\Models\Denda;

class DendaController extends Controller
{
   public function index(Request $request)
   {
      // Fetch Peminjaman data along with related member, book, and denda information
      $peminjamans = Peminjaman::with('member', 'book', 'denda')->paginate(10);

      return view('Denda.daftardenda', compact('peminjamans'));
   }

   public function bayarDenda(Request $request)
   {
      // Validate input
      $request->validate([
         'id_pjmn' => 'required|exists:tbl_peminjaman,id',
         'uang_yg_dibyrkn' => 'required|numeric|min:0',
      ]);

      // Find the Peminjaman record
      $peminjaman = Peminjaman::findOrFail($request->id_pjmn);

      // Check if the Peminjaman status is already 'lunas'
      if ($peminjaman->status === 'lunas') {
         return redirect()->back()->with('msg', 'Peminjaman sudah lunas.')->with('error', true);
      }

      // Update the 'uang_yg_dibyrkn' field in Denda model
      $denda = Denda::where('id_pjmn', $peminjaman->id)->first();
      if ($denda) {
         // Update existing Denda record
         $denda->uang_yg_dibyrkn = $request->uang_yg_dibyrkn;
         $denda->status = 'lunas'; // Assuming updating the status here if payment completes the denda
         $denda->save();
      } else {
         // Create a new Denda record if none exists (though you're updating here)
         $denda = new Denda();
         $denda->id_pjmn = $peminjaman->id;
         $denda->resi_pjmn = $peminjaman->resi_pjmn;
         $denda->uang_yg_dibyrkn = $request->uang_yg_dibyrkn;
         $denda->status = 'lunas'; // Assuming updating the status here if payment completes the denda
         $denda->save();
      }

      // Update Peminjaman status to 'lunas'
      $peminjaman->status = 'lunas';
      $peminjaman->save();

      // Redirect back with success message
      return redirect()->back()->with('msg', 'Pembayaran denda berhasil tersimpan. Member tersebut sudah tercatat dalam riwayat transaksi.')->with('success', true);
   }
}