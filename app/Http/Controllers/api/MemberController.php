<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail; // Import Mail class
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use App\Events\UserCreated;
use App\Mail\VerifyEmail; // Import VerifyEmail Mailable

class MemberController extends Controller
{
    // Metode untuk registrasi anggota baru
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'email' => 'required|email|max:255|unique:tbl_users,email',
            'password' => 'required|string|min:4|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        // Periksa apakah email sudah ada di tabel tbl_members
        $existingMember = Member::where('email', $request->email)->first();
        if ($existingMember) {
            return response()->json(['message' => 'Email sudah terdaftar sebagai anggota'], 400);
        }

        // Generate Unique OTP
        do {
            $otp = rand(10000, 99999);
            $existingOtp = User::where('verification_token', $otp)->exists();
        } while ($existingOtp);

        // Hash kata sandi sebelum menyimpannya
        $hashedPassword = Hash::make($request->password);

        // Buat pengguna dengan kata sandi hash dan OTP unik
        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'password' => $hashedPassword,
            'verification_token' => $otp,
            'role' => 'member',
        ]);

        // Generate QR code for the user
        $qrCodeData = $this->generateEncryptedQRCodeData($user->id);
        $user->qr_code = $qrCodeData;
        $user->save();

        // Trigger the UserCreated event
        event(new UserCreated($user));

        $qrCodeUrl = url('qrcodes/' . $user->qr_code);// Mengubah path untuk bisa diakses secara publik

        // Send email with OTP
        Mail::to($user->email)->send(new VerifyEmail($otp));

        return response()->json([
            'message' => 'Silakan periksa email Anda untuk kode verifikasi OTP.',
            'qr_code_url' => $qrCodeUrl,
            'user_id' => $user->id,
        ], 201);
    }

    public function verifyEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'otp' => 'required|digits:5',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        // Cari pengguna berdasarkan kode otp
        $user = User::where('verification_token', $request->otp)->first();

        // Periksa apakah OTP yang dimasukkan sesuai dengan yang tersimpan
        if (!$user || $user->verification_token != $request->otp) {
            return response()->json(['message' => 'Kode OTP tidak valid.'], 400);
        }

        // Update waktu verifikasi email dan hapus token OTP
        $user->email_verified_at = now();
        $user->verification_token = null;
        $user->save();

        return response()->json([
            'message' => 'Email berhasil diverifikasi. Anda sekarang dapat login.',
            'user' => $user,
        ], 200);
    }


    // Metode untuk memperbarui informasi anggota
    public function update(Request $request, $user_id)
    {
        $member = Member::where('user_id', $user_id)->first();

        if (!$member) {
            return response()->json(['message' => 'Pengguna tidak ditemukan'], 404);
        }

        // Check if user is trying to change readonly fields
        if ($request->has('first_name') && $request->first_name !== $member->first_name) {
            return response()->json(['message' => 'Nama depan tidak dapat diubah'], 400);
        }
        if ($request->has('last_name') && $request->last_name !== $member->last_name) {
            return response()->json(['message' => 'Nama belakang tidak dapat diubah'], 400);
        }
        if ($request->has('email') && $request->email !== $member->email) {
            return response()->json(['message' => 'Email tidak dapat diubah'], 400);
        }

        $validator = Validator::make($request->all(), [
            'password' => 'sometimes|string|min:5',
            'phone' => 'sometimes|string|max:20',
            'address' => 'sometimes|string',
            'tgl_lahir' => 'sometimes|date',
            'imageProfile' => 'sometimes|image|mimes:jpeg,png,jpg,gif|max:5120', // Maksimum 5 MB
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        if ($request->hasFile('imageProfile')) {
            $imageFile = $request->file('imageProfile');

            // Memeriksa apakah file gambar terlalu besar
            if ($imageFile->getSize() > 5120 * 1024) {
                return response()->json(['message' => 'Ukuran gambar terlalu besar. Ukuran maksimalnya adalah 5MB.'], 400);
            }

            // Buat nama file gambar profil acak dengan menggunakan Str::random()
            $imageFileName = Str::random(5) . '.' . $imageFile->getClientOriginalExtension();

            // Simpan gambar profil di folder public/profiles
            $imageFile->move(public_path('profiles'), $imageFileName);

            // Hapus file gambar profil lama jika ada
            if ($member->imageProfile) {
                $oldImagePath = public_path('profiles/' . $member->imageProfile);
                if (file_exists($oldImagePath)) {
                    unlink($oldImagePath);
                }
            }

            $member->imageProfile = $imageFileName;

            // Tambahkan pernyataan log untuk memeriksa lokasi penyimpanan gambar
            info('Berkas berhasil diunggah. Disimpan di: ' . $imageFileName);
        } else {
            info('Tidak ada file gambar yang diunggah.');
        }

        // Update other fields...
        if ($request->has('password')) {
            $member->password = Hash::make($request->password);
        }
        if ($request->has('phone')) {
            $member->phone = $request->phone;
        }
        if ($request->has('address')) {
            $member->address = $request->address;
        }
        if ($request->has('tgl_lahir')) {
            $member->tgl_lahir = $request->tgl_lahir;
        }

        $member->save();

        // Buat URL untuk gambar profil dan QR code
        $qrCodeUrl = url('qrcodes/' . $member->qr_code);
        $imageProfileUrl = url('profiles/' . $member->imageProfile);

        return response()->json([
            'message' => 'Member updated successfully',
            'member' => $member,
            'qr_code_url' => $qrCodeUrl,
            'image_profile_url' => $imageProfileUrl,
        ], 200);
    }

    

    public function show(Request $request, $user_id)
    {
        $member = Member::where('user_id', $user_id)->first();

        if (!$member) {
            return response()->json(['message' => 'Pengguna tidak ditemukan'], 404);
        }

        // Anda bisa menambahkan informasi tambahan yang diperlukan dalam respons JSON
        $qrCodeUrl = url('qrcodes/' . $member->qr_code);
        $imageProfileUrl = url('profiles/' . $member->imageProfile);

        return response()->json([
            'message' => 'Data pengguna berhasil diambil',
            'member' => $member,
            'qr_code_url' => $qrCodeUrl,
            'image_profile_url' => $imageProfileUrl,
        ], 200);
    }

    private function generateEncryptedQRCodeData($memberId)
    {
        $member = User::find($memberId);

        $data = [
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'email' => $member->email,
            'imageProfile' => $member->imageProfile ? asset('profiles/' . $member->imageProfile) : null,
        ];

        $encryptedData = Crypt::encryptString(json_encode($data));
        $qrCode = new QrCode($encryptedData);
        $writer = new PngWriter();

        // Ubah format nama file QR code menjadi sesuai dengan data anggota
        $qrCodeFileName = $member->first_name . '-' . $member->last_name . '.png';

        $qrCodePath = 'qrcodes/' . $qrCodeFileName; // Mengubah lokasi penyimpanan QR code

        $qrCodeData = $writer->write($qrCode)->getString();

        file_put_contents(public_path($qrCodePath), $qrCodeData);

        return $qrCodeFileName;
    }
}