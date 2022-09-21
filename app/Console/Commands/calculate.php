<?php
declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use League\Csv\Reader;
use League\Csv\Statement;

class calculate extends Command
{
    private array $rates = [];

    const RATES_URL = 'https://developers.paysera.com/tasks/api/currency-exchange-rates';
    const DEFAULT_PRECISION = 2;
    const DEPOSIT_FEE_PERCENT = 0.03;
    const BUSINESS_WITHDRAW_FEE_PERCENT = 0.5;
    const PRIVATE_WITHDRAW_FEE_PERCENT = 0.3;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'calculate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'calculate deposit & withdraw fee';

    private function roundFee ( $value, $precision = 2 ): string
    {
        $pow = pow ( 10, $precision );
        $rounded =  ( ceil ( $pow * $value ) + ceil ( $pow * $value - ceil ( $pow * $value ) ) ) / $pow;
        return  number_format($rounded,2,'.','');
    }



    public function withdraw(float $amount,string $type,array $privateWithdraw,collection $privateWithdraws): ?string
    {

        if($type === 'business'){
          return $this->roundFee($amount * self::BUSINESS_WITHDRAW_FEE_PERCENT / 100,self::DEFAULT_PRECISION);
        }

        $accountWithdraws = $privateWithdraws->where(1,'=',$privateWithdraw[1]);

        $firstWithdraw = $accountWithdraws->first()[0];
        $freeWithdrawLastDate = Carbon::create($firstWithdraw)->addWeek()->format('Y-m-d');

        $freeLimitWithdrawsCount = $privateWithdraws
            ->where(0,'<',$freeWithdrawLastDate)
            ->count();

        $currency = $privateWithdraw[5];

        if($freeLimitWithdrawsCount <=3){
            return '0';
        }else{
            if($currency !== 'EUR'){
               $rate = $this->rates[$currency];
               $amount = $amount * $rate / 100;
            }
            return $this->roundFee($amount * self::PRIVATE_WITHDRAW_FEE_PERCENT / 100,self::DEFAULT_PRECISION);
        }

     }

    public function deposit(float $amount): string
    {
        return $this->roundFee($amount * self::DEPOSIT_FEE_PERCENT / 100,self::DEFAULT_PRECISION);
    }

    public function handle()
    {
        try{
            $ratesQuery = Http::get(self::RATES_URL);
            $this->rates = $ratesQuery->json()['rates'];

            $csv = Reader::createFromPath(public_path('input.csv'), 'r');
            $stmt = Statement::create();

            $records = $stmt->process($csv);
            $privateWithdraws = collect($records)
                ->where(2,'=',"private")
                ->where(3,'=',"withdraw");

            foreach ($records as $record) {
                $method = $record[3];
                $amount = (float)($record[4]);
                $type = $record[2];

                if(!method_exists( $this,$method)){
                    throw new \Exception('Incorrect Type of transaction');
                }


                echo $this->$method($amount,$type,$record,$privateWithdraws);
                echo "\n";
            }

        }catch (\Exception $e){
            echo $e->getMessage();
        }
    }
}
