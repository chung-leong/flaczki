package lex.book 
{
	/**
	 * ...
	 * @author Lexcuk
	 */
	public class LinkPageAnimation
	{
		private var dropPage:DropPage;
		private var nav:PageNavigator;

		private var slowing:Slowing;
		private var speedDelim:Number = 10;
		private var s:BookStyle;
		private var animaTimeOutTr:Boolean;
		public var callBackContentRestoreAction:Function;
		public var imBusy:Boolean;
		public function LinkPageAnimation(dropPage:DropPage, nav:PageNavigator) 
		{
			this.dropPage = dropPage;
			this.nav = nav;
			s = dropPage.s;
			slowing = new Slowing(s.width / speedDelim);
			slowing.callBack = slowingCall;
		}

		public function animatePageTo(pageInd:int):void {
			if (nav.testAction(pageInd)) {
				imBusy = true;
				nav.action(nav.cur, pageInd);
				if (callBackContentRestoreAction != null) callBackContentRestoreAction();
				if (nav.leftTr) {
					slowing.setCurrent(s.bl.x+10, s.bl.y-10);
					slowing.setCoord(s.br.x+5, s.br.y);
				} else {
					slowing.setCurrent(s.br.x-10, s.br.y-10);
					slowing.setCoord(s.bl.x-5, s.bl.y);
				}
				animaTimeOutTr = true;
				
				slowing.doneTr = false;
			}
		}

		private function slowingCall():void {
			//var reactionTr:Boolean = true;
			
			if (animaTimeOutTr) {//временное стремление к серидине дуги анимации
				slowing.setTimeFin(0, s.height - s.height / 3);
				animaTimeOutTr = false;
			}
			
			if (slowing.doneTr) {
				nav.cur = nav.aim;
				imBusy = false;
				dropPage.dropShow(false,true);
				if (callBackContentRestoreAction != null) callBackContentRestoreAction();
				//trace('перелстывание анимации линк завершилось');
				
			}else {
				dropPage.dropShow(true,true);
				dropPage.action(slowing.curV2.x, slowing.curV2.y);
			}
		}
	}

}