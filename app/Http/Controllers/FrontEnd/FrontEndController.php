<?php

namespace App\Http\Controllers\FrontEnd;

use Exception;
use Midtrans\Snap;
use App\Models\Cart;
use Midtrans\Config;
use App\Models\Product;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\TransactionItem;
use App\Http\Controllers\Controller;

class FrontEndController extends Controller
{
    //
    public function index()
    {
        $category = Category::select('id', 'name', 'slug')->latest()->get();
        $product = Product::with('product_galleries')->select('id', 'name', 'slug', 'price')->latest()->limit(8)->get();

        return view('pages.frontend.index', compact(
            'category',
            'product'
        ));
    }

    public function detailProduct($slug)
    {
        $category = Category::select('id', 'name', 'slug')->latest()->get();
        $product = Product::with('product_galleries')->where('slug', $slug)->first();
        $recommendation = Product::with('product_galleries')->select('id', 'name', 'slug', 'price')->inRandomOrder()->limit(4)->get();

        return view('pages.frontend.detail-product', compact(
            'product',
            'category',
            'recommendation'
        ));
    }

    public function detailCategory($slug)
    {
        $category = Category::select('id', 'name', 'slug')->latest()->get();
        $categories = Category::where('slug', $slug)->first();
        $product = Product::with('product_galleries')->where('category_id', $categories->id)->latest()->get();

        return view('pages.frontend.detail-category', compact(
            'category',
            'categories',
            'product'
        ));
    }

    public function cart()
    {
        $category = Category::select('id', 'name', 'slug')->latest()->get();
        $cart = Cart::with('product')->where('user_id', auth()->user()->id)->latest()->get();
        return view('pages.frontend.cart', compact(
            'category',
            'cart'
        ));
    }

    public function addToCart(Request $request, $id)
    {
        try {
            Cart::create([
                'product_id' => $id,
                'user_id' => auth()->user()->id
            ]);

            return redirect()->route('cart');
            // return redirect()->route('cart')->with('success', 'berhasil dijalankan');
        } catch (\Exception $e) {
            // dd($e->getMessage());
            return redirect()->back()->with('error', 'Terjadi kesalahan');
        }
    }

    public function deleteCart($id)
    {
        try {
            Cart::findOrFail($id)->delete();
            return redirect()->route('cart');
        } catch (Exception $e) {
            // dd($e->getMessage());
            return redirect()->back();
        }
    }

    public function checkout(Request $request)
    {
        try {
            //    request data
            $data = $request->all();

            // get data cart user
            $cart = Cart::with('product')->where('user_id', auth()->user()->id)->get();
            // dd($cart);

            // create transaction
            $transaction = Transaction::create([
                'user_id' => auth()->user()->id,
                'name' => $data['name'],
                'slug' => Str::slug($data['name']) . '-' . time(),
                'email' => $data['email'],
                'address' => $data['address'],
                'phone' => $data['phone'],
                'total_price' => $cart->sum('product.price')
            ]);

            // dd($transaction);

            // cerate transactionn item
            foreach ($cart as $item) {
                TransactionItem::create([
                    'user_id' => auth()->user()->id,
                    'product_id' => $item->product_id,
                    'transaction_id' => $transaction->id
                ]);
            }

            // delete cart
            Cart::where('user_id', auth()->user()->id)->delete();

            // setting midtrans
            // use Midtrans\Config;
            // use Midtrans\Snap;
            Config::$serverKey = config('services.midtrans.server_key');
            Config::$clientKey = config('services.midtrans.client_key');
            Config::$isProduction = config('services.midtrans.is_production');
            Config::$isSanitized = config('services.midtrans.isSanitized');
            Config::$is3ds = config('services.midtrans.is3ds');

            // setup variabel for midtrans
            $midtrans = [
                'transaction_details' => [
                    'order_id' => 'ilham' . $transaction->id,
                    'gross_amount' => (int) $transaction->total_price
                ],
                'custumer_details' => [
                    'first_name' => $transaction->name,
                    'email' => $transaction->email,
                    'phone' => $transaction->phone,
                ],
                'enable_payments' => ['gopay', 'bank_transfer'],
                'vtweb' => []
            ];

            // create payment url from midtrans
            $paymentUrl = Snap::createTransaction($midtrans)->redirect_url;

            // update payment url
            $transaction->update([
                'payment_url' => $paymentUrl
            ]);

            return redirect($paymentUrl);
        } catch (\Exception $e) {
            dd($e->getMessage());
            return redirect()->back();
        }
    }
}
